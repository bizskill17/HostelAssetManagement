<?php
/**
 * Email and Telegram notifications for transfers (sent / received) and repairs.
 * Who gets emails: all active Admins, the wardens of both hostels involved, and any extra
 * addresses in Settings. Telegram posts one message to the group set in Settings.
 * A failed notification never blocks the stock change; it is written to the PHP error log.
 */
declare(strict_types=1);

function settings(): array
{
    $s = [];
    foreach (db()->query("SELECT k, v FROM ha_settings")->fetchAll() as $r) {
        $s[$r['k']] = $r['v'];
    }
    return $s;
}

function notifyIssue(string $event, array $i, array $by, array $extra = []): void
{
    try {
        $s = settings();
        $emailOn = ($s['email_on'] ?? '0') === '1';
        $tgOn = ($s['telegram_on'] ?? '0') === '1' && ($s['telegram_token'] ?? '') !== '' && ($s['telegram_chat'] ?? '') !== '';
        if (!$emailOn && !$tgOn) {
            return;
        }
        $from = hostelRow((int) $i['hostel_id']);
        $to = hostelRow($i['to_hostel_id'] === null ? null : (int) $i['to_hostel_id']);
        $item = itemRow((int) $i['item_id']);
        $no = issueNo($i);
        $qty = (int) $i['qty'] . ' ' . ($item['unit'] ?? '');
        $what = ($item['name'] ?? 'Item') . " × $qty";
        $fromName = $from['name'] ?? '';
        $toName = $to['name'] ?? '';

        switch ($event) {
            case 'transfer_sent':
                $subject = "Transfer $no: $fromName → $toName needs approval";
                $lines = ["$fromName is sending $what to $toName.", "$toName: please approve when the items arrive."];
                break;
            case 'transfer_received':
                $got = (int) $i['received_qty'];
                $sent = (int) $i['qty'];
                $unit = $item['unit'] ?? '';
                if ($got === 0) {
                    $subject = "Transfer $no: nothing received at $toName";
                    $lines = ["$toName reports none of the $what from $fromName arrived.", "The $sent $unit stay on $fromName's stock."];
                } elseif ($got < $sent) {
                    $subject = "Transfer $no: $toName received $got of $sent";
                    $lines = ["$toName received $got of $sent $unit of " . ($item['name'] ?? 'item') . " from $fromName.", ($sent - $got) . " $unit short — they stay on $fromName's stock."];
                } else {
                    $subject = "Transfer $no received by $toName";
                    $lines = ["$toName received $what from $fromName.", "Stock has moved from $fromName to $toName."];
                }
                if ($i['decision_note'] !== '') {
                    $lines[] = 'Note from ' . $toName . ': ' . $i['decision_note'];
                }
                break;
            case 'repair_sent':
                $subject = "Repair $no: $what sent from $fromName";
                $lines = ["$fromName sent $what for repair" . ($i['place'] !== '' ? " to {$i['place']}" : '') . '.'];
                if ($i['expected_at']) {
                    $lines[] = 'Expected back: ' . date('d/m/Y', strtotime($i['expected_at']));
                }
                break;
            case 'repair_returned':
                $subject = "Repair $no: items back at $fromName";
                $lines = [($item['name'] ?? 'Item') . " back from repair at $fromName: " . ($extra['ok'] ?? 0) . ' repaired'
                    . (!empty($extra['scrap']) ? ', ' . $extra['scrap'] . ' not repairable (marked damaged)' : '') . '.'];
                break;
            default:
                return;
        }
        if ($i['notes'] !== '') {
            $lines[] = 'Notes: ' . $i['notes'];
        }
        $lines[] = 'By: ' . $by['name'];
        if (($s['app_url'] ?? '') !== '') {
            $lines[] = 'Open: ' . $s['app_url'];
        }

        if ($emailOn) {
            $hostels = array_filter([(int) $i['hostel_id'], $i['to_hostel_id'] === null ? 0 : (int) $i['to_hostel_id']]);
            $ph = implode(',', array_fill(0, count($hostels), '?'));
            $emails = q("SELECT email FROM ha_users WHERE active = 1 AND (role = 'Admin' OR hostel_id IN ($ph))", array_values($hostels))->fetchAll(PDO::FETCH_COLUMN);
            foreach (preg_split('/[,;\s]+/', $s['email_to'] ?? '') as $e) {
                if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = $e;
                }
            }
            sendMail(array_values(array_unique(array_map('strtolower', $emails))), $subject, $lines, $s);
        }
        if ($tgOn) {
            sendTelegram($s, "<b>" . htmlspecialchars($subject) . "</b>\n" . htmlspecialchars(implode("\n", $lines)));
        }
    } catch (Throwable $e) {
        error_log('[hostel-assets] notify failed: ' . $e->getMessage());
    }
}

function sendMail(array $to, string $subject, array $lines, array $s): bool
{
    if (!$to) {
        return false;
    }
    $host = preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $from = ($s['email_from'] ?? '') !== '' ? $s['email_from'] : "no-reply@$host";
    $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1f2937">'
        . '<h2 style="font-size:17px;margin:0 0 12px">' . htmlspecialchars($subject) . '</h2>'
        . implode('', array_map(fn($l) => '<p style="margin:0 0 8px">' . htmlspecialchars($l) . '</p>', $lines))
        . '<p style="margin-top:18px;color:#6b7280;font-size:12px">Hostel Asset Management</p></div>';
    $headers = [
        'MIME-Version: 1.0', 'Content-Type: text/html; charset=UTF-8',
        'From: Hostel Assets <' . $from . '>', 'Bcc: ' . implode(', ', array_slice($to, 1)),
    ];
    if (count($to) === 1) {
        array_pop($headers);
    }
    $ok = @mail($to[0], '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, implode("\r\n", $headers), '-f' . $from);
    if (!$ok) {
        error_log('[hostel-assets] mail() failed for: ' . $subject);
    }
    return $ok;
}

function sendTelegram(array $s, string $html): array
{
    $ch = curl_init('https://api.telegram.org/bot' . $s['telegram_token'] . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_POSTFIELDS => ['chat_id' => $s['telegram_chat'], 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true'],
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

function sendTest(array $u): array
{
    $s = settings();
    $r = [];
    if (($s['email_on'] ?? '0') === '1') {
        $to = array_values(array_filter(array_merge([$u['email']], preg_split('/[,;\s]+/', $s['email_to'] ?? '')), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        $r['email'] = sendMail($to, 'Test notification', ['This is a test from Hostel Asset Management.', 'Emails for transfers and repairs will look like this.'], $s)
            ? ['ok' => true, 'message' => 'Sent to ' . implode(', ', $to)]
            : ['ok' => false, 'message' => 'The server could not send the email. Check the From address is a mailbox on your domain.'];
    } else {
        $r['email'] = ['ok' => false, 'message' => 'Email notifications are off.'];
    }
    if (($s['telegram_on'] ?? '0') === '1' && ($s['telegram_token'] ?? '') !== '' && ($s['telegram_chat'] ?? '') !== '') {
        $r['telegram'] = sendTelegram($s, "<b>Test notification</b>\nHostel Asset Management is connected to this group.");
        if ($r['telegram']['ok']) {
            $r['telegram']['message'] = 'Posted to the group.';
        }
    } else {
        $r['telegram'] = ['ok' => false, 'message' => 'Telegram is off, or the bot token / chat ID is missing.'];
    }
    return $r;
}
