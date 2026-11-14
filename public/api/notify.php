<?php
/**
 * Telegram notifications.
 * Every message goes to the main group in Settings and also to the Telegram group of each unit
 * involved (Masters → Units) and of its Foundation (Masters → Foundations). Each message type can be switched off in Settings.
 * Daily messages (repairs overdue, warranties expiring, morning summary) are sent once a day after the
 * time set in Settings: by the cron URL if one is set up, otherwise on the first app visit after that time.
 * A failed notification never blocks the stock change; it is written to the PHP error log.
 */
declare(strict_types=1);

const TG_EVENTS = [
    'stock_in' => 'Stock received',
    'transfer_sent' => 'Transfer sent',
    'transfer_received' => 'Transfer received / short / not received',
    'transfer_cancelled' => 'Transfer cancelled',
    'damage_reported' => 'Damage reported (waiting for approval)',
    'damage_decided' => 'Damage approved / rejected',
    'repair_sent' => 'Repair sent',
    'repair_returned' => 'Repair returned',
    'count_saved' => 'Physical count saved',
    'daily_overdue' => 'Daily: repairs overdue',
    'daily_warranty' => 'Daily: warranties expiring',
    'daily_summary' => 'Daily: morning summary',
];
const WARRANTY_ALERT_DAYS = [30, 15, 7, 3, 1, 0];

function settings(): array
{
    $s = [];
    foreach (db()->query("SELECT k, v FROM ha_settings")->fetchAll() as $r) {
        $s[$r['k']] = $r['v'];
    }
    return $s;
}

function tgReady(array $s): bool
{
    return ($s['telegram_on'] ?? '0') === '1' && ($s['telegram_token'] ?? '') !== '';
}

function tgEventOn(array $s, string $event): bool
{
    return !in_array($event, array_filter(explode(',', $s['tg_off'] ?? '')), true);
}

function h(string $t): string
{
    return htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function dmy(?string $d): string
{
    return $d ? date('d/m/Y', strtotime($d)) : '';
}

/** Chats for a message: the main group plus the groups of the hostels involved, without repeats. */
function tgChats(array $s, array $hostelIds): array
{
    $chats = [];
    if (($s['telegram_chat'] ?? '') !== '') {
        $chats[] = $s['telegram_chat'];
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $hostelIds))));
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        foreach (q("SELECT tg_chat FROM ha_hostels WHERE id IN ($ph) AND tg_chat <> ''", $ids)->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $chats[] = $c;
        }
        // …and the groups of the Foundations those units and warehouses belong to.
        foreach (q("SELECT DISTINCT f.tg_chat FROM ha_foundations f JOIN ha_hostels h ON h.foundation_id = f.id WHERE h.id IN ($ph) AND f.tg_chat <> ''", $ids)->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $chats[] = $c;
        }
    }
    return array_values(array_unique($chats));
}

function tgFooter(array $s): string
{
    $url = $s['app_url'] ?? '';
    return $url !== '' ? "\n🔗 <a href=\"" . h($url) . "\">Open app</a>" : '';
}

function tgPost(array $s, string $event, array $hostelIds, string $html): void
{
    if (!tgReady($s) || !tgEventOn($s, $event)) {
        return;
    }
    foreach (tgChats($s, $hostelIds) as $chat) {
        sendTelegram($s, $html, $chat);
    }
}

function notifyIssue(string $event, array $i, array $by, array $extra = []): void
{
    try {
        $s = settings();
        $key = in_array($event, ['damage_approved', 'damage_rejected'], true) ? 'damage_decided' : $event;
        if (!tgReady($s) || !tgEventOn($s, $key)) {
            return;
        }
        $from = h(hostelRow((int) $i['hostel_id'])['name'] ?? '');
        $to = h(hostelRow($i['to_hostel_id'] === null ? null : (int) $i['to_hostel_id'])['name'] ?? '');
        $item = itemRow((int) $i['item_id']);
        $name = h($item['name'] ?? 'Item');
        $unit = h($item['unit'] ?? '');
        $no = issueNo($i);
        $qty = (int) $i['qty'];
        $what = "$name × $qty $unit";
        $note = $i['decision_note'] !== '' ? h($i['decision_note']) : '';

        switch ($event) {
            case 'transfer_sent':
                $L = ["🔁 <b>Transfer $no · waiting for approval</b>", "$from → $to", $what, "⏳ $to: please update received qty when items arrive"];
                break;
            case 'transfer_received':
                $got = (int) $i['received_qty'];
                if ($got === 0) {
                    $L = ["❌ <b>Transfer $no not received</b>", "$from → $to", "$what — nothing arrived", "Stock stays with $from"];
                } elseif ($got < $qty) {
                    $L = ["⚠️ <b>Transfer $no received short</b>", "$from → $to", "$name: sent $qty · received $got · short " . ($qty - $got),
                        'The ' . ($qty - $got) . " $unit stay on $from stock"];
                } else {
                    $L = ["✅ <b>Transfer $no received</b>", "$from → $to", "$what — all received", "Stock moved to $to"];
                }
                if ($note !== '') {
                    $L[] = "Note from $to: $note";
                }
                break;
            case 'transfer_cancelled':
                $L = ["🚫 <b>Transfer $no cancelled</b>", "$from → $to", $what];
                break;
            case 'damage_reported':
                $L = ["🟥 <b>Damage $no · waiting for approval</b>", $from, $what . ($i['place'] !== '' ? ' → ' . h($i['place']) : '')];
                break;
            case 'damage_approved':
                $got = (int) $i['received_qty'];
                $L = ["✅ <b>Damage $no approved</b>", $from];
                if ($got < $qty) {
                    $L[] = "$name: reported $qty · approved $got";
                    $L[] = ($qty - $got) . " $unit stay in stock";
                } else {
                    $L[] = "$what written off" . ($i['place'] !== '' ? ' → ' . h($i['place']) : '');
                }
                if ($note !== '') {
                    $L[] = "Note: $note";
                }
                break;
            case 'damage_rejected':
                $L = ["❌ <b>Damage $no rejected</b>", $from, "$what — stock unchanged", "Reason: $note"];
                break;
            case 'repair_sent':
                $L = ["🔧 <b>Repair $no sent" . (($i['repair_type'] ?? '') === 'Internal' ? ' (internal)' : '') . "</b>", $from, $what . ($i['place'] !== '' ? ' → ' . h($i['place']) : '')];
                if ($i['expected_at']) {
                    $L[] = 'Expected back: ' . dmy($i['expected_at']);
                }
                break;
            case 'repair_returned':
                $left = $qty - (int) $i['returned_qty'] - (int) $i['scrapped_qty'];
                $L = ["🔧 <b>Repair $no returned</b>", "$from · $name", 'Repaired: ' . (int) ($extra['ok'] ?? 0) . " $unit"
                    . (!empty($extra['scrap']) ? ' · Not repairable: ' . (int) $extra['scrap'] . " $unit (sent for damage approval)" : ''),
                    "Still at repair: $left"];
                break;
            default:
                return;
        }
        if ($i['notes'] !== '' && $event !== 'transfer_received' && $event !== 'damage_rejected' && $event !== 'damage_approved') {
            $L[] = 'Notes: ' . h($i['notes']);
        }
        $L[] = 'By: ' . h($by['name']);
        tgPost($s, $key, [(int) $i['hostel_id'], (int) ($i['to_hostel_id'] ?? 0)], implode("\n", $L) . tgFooter($s));
    } catch (Throwable $e) {
        error_log('[hostel-assets] notify failed: ' . $e->getMessage());
    }
}

function notifyStockIn(int $hid, int $iid, int $qty, string $party, string $notes, array $by): void
{
    try {
        $s = settings();
        $item = itemRow($iid);
        $L = ['📥 <b>Stock received</b>', h(hostelRow($hid)['name'] ?? ''), h($item['name'] ?? 'Item') . " × $qty " . h($item['unit'] ?? '')];
        if ($party !== '') {
            $L[] = 'From: ' . h($party);
        }
        if ($notes !== '') {
            $L[] = 'Notes: ' . h($notes);
        }
        $L[] = 'By: ' . h($by['name']);
        tgPost($s, 'stock_in', [$hid], implode("\n", $L) . tgFooter($s));
    } catch (Throwable $e) {
        error_log('[hostel-assets] notify failed: ' . $e->getMessage());
    }
}

/* ---------------------------------------------------------------- daily messages */

/** "1. …\n2. …" — long lists are cut at 25 lines so the message stays under Telegram's size limit. */
function numList(array $rows, int $max = 25): string
{
    $out = [];
    foreach (array_slice(array_values($rows), 0, $max) as $k => $r) {
        $out[] = ($k + 1) . '. ' . $r;
    }
    if (count($rows) > $max) {
        $out[] = '…and ' . (count($rows) - $max) . ' more';
    }
    return implode("\n", $out);
}

/** Runs the daily messages once per day, after the time in Settings. Returns what was sent. */
function runDaily(bool $force = false): array
{
    $s = settings();
    if (!tgReady($s)) {
        return ['sent' => 0, 'message' => 'Telegram is off.'];
    }
    $today = date('Y-m-d');
    if (!$force) {
        if (date('H:i') < ($s['daily_time'] ?? '09:00')) {
            return ['sent' => 0, 'message' => 'Not time yet.'];
        }
        // Claim today's run so two requests can't both send it.
        q("INSERT IGNORE INTO ha_settings (k, v) VALUES ('last_daily', '')");
        if (q("UPDATE ha_settings SET v = ? WHERE k = 'last_daily' AND v <> ?", [$today, $today])->rowCount() === 0) {
            return ['sent' => 0, 'message' => 'Already sent today.'];
        }
    }
    $hostels = [];
    foreach (db()->query("SELECT id, name, tg_chat, foundation_id FROM ha_hostels")->fetchAll() as $r) {
        $hostels[(int) $r['id']] = $r;
    }
    $founds = db()->query("SELECT id, tg_chat FROM ha_foundations WHERE tg_chat <> ''")->fetchAll();
    $hn = fn($id) => h($hostels[(int) $id]['name'] ?? '');
    $foot = tgFooter($s);
    $main = $s['telegram_chat'] ?? '';
    $sent = 0;
    $post = function (string $chat, string $html) use ($s, &$sent) {
        if ($chat !== '' && sendTelegram($s, $html, $chat)['ok']) {
            $sent++;
        }
    };
    // Sends the full list to the main group, each unit's own lines to that unit's group,
    // and each Foundation's lines (its warehouse and units) to the Foundation's group.
    $fanOut = function (string $event, string $title, array $lines) use ($s, $hostels, $founds, $main, $foot, $post) {
        if (!$lines || !tgEventOn($s, $event)) {
            return;
        }
        $post($main, "$title — " . count($lines) . "</b>\n" . numList(array_column($lines, 1)) . $foot);
        foreach ($hostels as $hid => $hr) {
            $mine = array_values(array_filter($lines, fn($l) => $l[0] === $hid));
            if ($mine && $hr['tg_chat'] !== '' && $hr['tg_chat'] !== $main) {
                $post($hr['tg_chat'], "$title — " . count($mine) . "</b>\n" . numList(array_column($mine, 1)) . $foot);
            }
        }
        foreach ($founds as $f) {
            $mine = array_values(array_filter($lines, fn($l) => (int) ($hostels[$l[0]]['foundation_id'] ?? 0) === (int) $f['id']));
            if ($mine && $f['tg_chat'] !== $main) {
                $post($f['tg_chat'], "$title — " . count($mine) . "</b>\n" . numList(array_column($mine, 1)) . $foot);
            }
        }
    };

    // Repairs overdue
    $over = [];
    foreach (q("SELECT * FROM ha_issues WHERE kind = 'repair' AND status IN ('at_repair', 'partly_returned') AND expected_at IS NOT NULL AND expected_at < ? ORDER BY expected_at", [$today])->fetchAll() as $i) {
        $item = itemRow((int) $i['item_id']);
        $out = (int) $i['qty'] - (int) $i['returned_qty'] - (int) $i['scrapped_qty'];
        $late = (int) ((strtotime($today) - strtotime($i['expected_at'])) / 86400);
        $over[] = [(int) $i['hostel_id'], issueNo($i) . ' ' . h($item['name'] ?? '') . " × $out · " . $hn($i['hostel_id'])
            . ($i['place'] !== '' ? ' · ' . h($i['place']) : '') . ' · due ' . date('d/m', strtotime($i['expected_at'])) . " ($late day" . ($late === 1 ? '' : 's') . ' late)'];
    }
    $fanOut('daily_overdue', '⏰ <b>Repairs overdue', $over);

    // Warranties expiring
    $warr = [];
    foreach (db()->query("SELECT * FROM ha_warranties ORDER BY expiry_at")->fetchAll() as $w) {
        $days = (int) ((strtotime($w['expiry_at']) - strtotime($today)) / 86400);
        if (!in_array($days, WARRANTY_ALERT_DAYS, true)) {
            continue;
        }
        $label = $w['title'] !== '' ? $w['title'] : (itemRow($w['item_id'] === null ? null : (int) $w['item_id'])['name'] ?? 'Warranty');
        $when = $days === 0 ? 'expires today' : "$days day" . ($days === 1 ? '' : 's') . ' left (' . dmy($w['expiry_at']) . ')';
        $warr[] = [(int) $w['hostel_id'], h($label) . ' · ' . ($w['hostel_id'] ? $hn($w['hostel_id']) : 'All units')
            . ($w['warranty_no'] !== '' ? ' · ' . h($w['warranty_no']) : '') . " — $when"];
    }
    $fanOut('daily_warranty', '🛡️ <b>Warranties expiring', $warr);

    // Morning summary (main group only): what is waiting, each as a numbered list.
    if (tgEventOn($s, 'daily_summary') && $main !== '') {
        $iname = fn($id) => h(itemRow((int) $id)['name'] ?? '');
        $iunit = fn($id) => h(itemRow((int) $id)['unit'] ?? '');
        $tr = array_map(fn($i) => $hn($i['hostel_id']) . ' → ' . $hn($i['to_hostel_id']) . ' - ' . $iname($i['item_id']) . ' - ' . (int) $i['qty'] . ' ' . $iunit($i['item_id']),
            q("SELECT * FROM ha_issues WHERE kind = 'transfer' AND status = 'pending' ORDER BY at, id")->fetchAll());
        $dm = array_map(fn($i) => $hn($i['hostel_id']) . ' - ' . $iname($i['item_id']) . ' - ' . (int) $i['qty'] . ' ' . $iunit($i['item_id']),
            q("SELECT * FROM ha_issues WHERE kind = 'damage' AND status = 'pending' ORDER BY at, id")->fetchAll());
        $rp = [];
        foreach (q("SELECT * FROM ha_issues WHERE kind = 'repair' AND status IN ('at_repair', 'partly_returned') ORDER BY at, id")->fetchAll() as $i) {
            $out = (int) $i['qty'] - (int) $i['returned_qty'] - (int) $i['scrapped_qty'];
            if ($out > 0) {
                $rp[] = ($i['place'] !== '' ? h($i['place']) : $hn($i['hostel_id'])) . ' - ' . $iname($i['item_id']) . ' - ' . $out . ' ' . $iunit($i['item_id']);
            }
        }
        $sec = fn($label, $rows) => "\n• <b>$label: " . count($rows) . "</b>" . ($rows ? "\n" . numList($rows) : '') . "\n";
        $post($main, "☀️ <b>Good morning — Hostel Assets, " . date('d/m/Y') . "</b>\nWaiting for approval\n"
            . $sec('Transfers', $tr) . $sec('Damage', $dm) . $sec('Repair', $rp) . $foot);
    }
    return ['sent' => $sent, 'message' => "Sent $sent message" . ($sent === 1 ? '' : 's') . '.'];
}

/* ---------------------------------------------------------------- sending */

function sendTelegram(array $s, string $html, ?string $chat = null): array
{
    $ch = curl_init((defined('TG_API') ? TG_API : 'https://api.telegram.org') . '/bot' . $s['telegram_token'] . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_POSTFIELDS => ['chat_id' => $chat ?? ($s['telegram_chat'] ?? ''), 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true'],
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    $j = is_string($res) ? json_decode($res, true) : null;
    if (!is_array($j) || empty($j['ok'])) {
        $msg = $err ?: (is_array($j) ? ($j['description'] ?? 'unknown error') : 'no response');
        error_log('[hostel-assets] telegram failed: ' . $msg);
        return ['ok' => false, 'message' => $msg];
    }
    return ['ok' => true];
}

/** A physical count was saved: how many lines, and each difference. */
function notifyCount(int $hid, int $batch, string $at, int $lines, array $diffs, array $u): void
{
    try {
        $s = settings();
        if (!tgReady($s) || !tgEventOn($s, 'count_saved')) {
            return;
        }
        $no = 'PC-' . str_pad((string) $batch, 5, '0', STR_PAD_LEFT);
        $L = ["📋 <b>Physical count $no</b>", h(hostelRow($hid)['name'] ?? '') . ' · ' . dmy($at), "$lines item" . ($lines === 1 ? '' : 's') . ' counted · ' . (count($diffs) ? count($diffs) . ' with a difference (waiting for approval)' : 'all match')];
        foreach (array_slice($diffs, 0, 15) as [$iid, $book, $phys]) {
            $d = $phys - $book;
            $L[] = '• ' . h(itemRow($iid)['name'] ?? '') . ": system $book · counted $phys · " . ($d > 0 ? "+$d" : (string) $d);
        }
        $L[] = 'By: ' . h($u['name']);
        tgPost($s, 'count_saved', [$hid], implode("\n", $L) . tgFooter($s));
    } catch (Throwable $e) {
        error_log('[hostel-assets] notify failed: ' . $e->getMessage());
    }
}

/** Posts a test message to the main group and to every Foundation and unit group, and reports each result. */
function sendTest(): array
{
    $s = settings();
    if (($s['telegram_token'] ?? '') === '') {
        return [['name' => 'Telegram', 'ok' => false, 'message' => 'Add the bot token first.']];
    }
    $r = [];
    $targets = [];
    if (($s['telegram_chat'] ?? '') !== '') {
        $targets[] = ['Main group', $s['telegram_chat'], 'all foundations and units'];
    }
    foreach (db()->query("SELECT name, tg_chat FROM ha_foundations WHERE tg_chat <> '' ORDER BY name")->fetchAll() as $fr) {
        $targets[] = [$fr['name'] . ' group', $fr['tg_chat'], $fr['name'] . ' (warehouse and units)'];
    }
    foreach (db()->query("SELECT name, tg_chat FROM ha_hostels WHERE tg_chat <> '' ORDER BY name")->fetchAll() as $hr) {
        $targets[] = [$hr['name'] . ' group', $hr['tg_chat'], $hr['name']];
    }
    if (!$targets) {
        return [['name' => 'Telegram', 'ok' => false, 'message' => 'Add the main group chat ID first.']];
    }
    foreach ($targets as [$name, $chat, $scope]) {
        $res = sendTelegram($s, "✅ <b>Test message</b>\nHostel Asset Management is connected to this group.\nMessages for: " . h($scope) . tgFooter($s), $chat);
        $r[] = ['name' => $name, 'ok' => $res['ok'], 'message' => $res['ok'] ? 'Posted.' : $res['message']];
    }
    return $r;
}
