<?php
/**
 * Hostel Asset Management — JSON API (PHP 8 + MySQL/MariaDB on Hostinger).
 *
 * One organisation, many hostels. Stock is counted by quantity per hostel and item;
 * every change is a row in ha_txns, so any item's ledger can be rebuilt from opening onwards.
 *
 * Roles: Admin (everything, masters, settings), Organization (all Foundations and Units),
 * Foundation (its Central Warehouse and its Units) and Unit (one Unit only).
 * Actions are passed as ?a=...; POSTs carry JSON and the header X-Req: 1.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out(array $d, int $http = 200): void
{
    http_response_code($http);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}
function fail(string $message, int $http = 400, string $code = 'invalid'): void
{
    out(['error' => ['code' => $code, 'message' => $message]], $http);
}

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    fail('Server is not configured: api/config.php is missing. Check the DB_* secrets in GitHub.', 500, 'unavailable');
}
require $configFile;
require __DIR__ . '/install.php';
require __DIR__ . '/notify.php';
date_default_timezone_set(defined('APP_TZ') ? APP_TZ : 'Asia/Kolkata');

session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 30, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_name('ham_sess');
if (!defined('CRON_CLI')) {
    session_start();
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}
function q(string $sql, array $args = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st;
}
function body(): array
{
    $j = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($j) ? $j : [];
}
function str(mixed $v, int $max = 255): string
{
    return mb_substr(trim((string) ($v ?? '')), 0, $max);
}
function int0(mixed $v): int
{
    return (int) ($v ?? 0);
}
function idOrNull(mixed $v): ?int
{
    $i = (int) ($v ?? 0);
    return $i > 0 ? $i : null;
}
function dateOr(mixed $v, ?string $default): ?string
{
    $s = trim((string) ($v ?? ''));
    if ($s === '') {
        return $default;
    }
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    if (!$d || $d->format('Y-m-d') !== $s) {
        fail('Enter a valid date.');
    }
    return $s;
}
function today(): string
{
    return date('Y-m-d');
}
function nowMs(): int
{
    return (int) round(microtime(true) * 1000);
}

const ROLES = ['Admin', 'Organization', 'Foundation', 'Unit'];
const UNIT_TYPES = ['Hostel', 'College'];

function tgChatOk($v): string
{
    $tg = preg_replace('/\s+/', '', str((string) $v, 64));
    if ($tg !== '' && !preg_match('/^(-?\d+|@\w+)$/', $tg)) {
        fail('The Telegram group ID should be a number like -1001234567890.');
    }
    return $tg;
}

/* ---------- users & access ---------- */
function me(): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $u = q("SELECT id, name, email, role, hostel_id, foundation_id, active FROM ha_users WHERE id = ? AND password_hash IS NOT NULL", [$_SESSION['uid']])->fetch();
    if (!$u || !(int) $u['active']) {
        unset($_SESSION['uid']);
        return null;
    }
    $u['id'] = (int) $u['id'];
    $u['hostel_id'] = $u['hostel_id'] === null ? null : (int) $u['hostel_id'];
    $u['foundation_id'] = $u['foundation_id'] === null ? null : (int) $u['foundation_id'];
    return $u;
}
function requireUser(): array
{
    $u = me();
    if (!$u) {
        fail('Please sign in again.', 401, 'unauthenticated');
    }
    if ($u['role'] === 'Unit' && !$u['hostel_id']) {
        fail('Your login is not linked to a Unit yet. Ask an Admin to set your Unit.', 403, 'forbidden');
    }
    if ($u['role'] === 'Foundation' && !$u['foundation_id']) {
        fail('Your login is not linked to a Foundation yet. Ask an Admin to set your Foundation.', 403, 'forbidden');
    }
    return $u;
}
/** Admin and Organization logins see and work on every Foundation and Unit. */
function seesAll(array $u): bool
{
    return in_array($u['role'], ['Admin', 'Organization'], true);
}
/** Locations (Units and Central Warehouses) a login may work on; null means all of them. */
function scopeIds(array $u): ?array
{
    static $memo = [];
    if (seesAll($u)) {
        return null;
    }
    if ($u['role'] === 'Foundation') {
        return $memo[$u['id']] ??= array_map('intval', q("SELECT id FROM ha_hostels WHERE foundation_id = ?", [$u['foundation_id']])->fetchAll(PDO::FETCH_COLUMN));
    }
    return [(int) $u['hostel_id']];
}
/** "hostel_id IN (…)" for the login's locations, with its parameters. */
function scopeSql(array $u, string $col): array
{
    $ids = scopeIds($u) ?: [0];
    return ["$col IN (" . implode(',', array_fill(0, count($ids), '?')) . ")", $ids];
}
/** Damage is approved by an Admin, Organization, or the Foundation the location belongs to. */
function canApprove(array $u, int $hid): bool
{
    return seesAll($u) || ($u['role'] === 'Foundation' && canHostel($u, $hid));
}
function isAdmin(array $u): bool
{
    return $u['role'] === 'Admin';
}
function requireAdmin(): array
{
    $u = requireUser();
    if (!isAdmin($u)) {
        fail('Only an Admin can do this.', 403, 'forbidden');
    }
    return $u;
}
function canHostel(array $u, ?int $hid): bool
{
    if ($hid === null) {
        return false;
    }
    $ids = scopeIds($u);
    return $ids === null || in_array($hid, $ids, true);
}
function publicMe(?array $u): ?array
{
    return $u ? ['id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role'], 'hostelId' => $u['hostel_id'], 'foundationId' => $u['foundation_id']] : null;
}
function loginCount(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM ha_users WHERE password_hash IS NOT NULL AND active = 1")->fetchColumn();
}

/* ---------- stock ---------- */
function onHand(int $hid, int $iid): int
{
    return (int) q("SELECT COALESCE(SUM(dir * qty), 0) FROM ha_txns WHERE hostel_id = ? AND item_id = ?", [$hid, $iid])->fetchColumn();
}
function pendingOut(int $hid, int $iid, int $exceptIssue = 0): int
{
    // Stock set aside: transfers not yet received and damage not yet approved.
    return (int) q("SELECT COALESCE(SUM(qty), 0) FROM ha_issues WHERE kind IN ('transfer', 'damage') AND status = 'pending' AND hostel_id = ? AND item_id = ? AND id <> ?", [$hid, $iid, $exceptIssue])->fetchColumn();
}
/**
 * Records damage. Every entry, an Admin's too, waits in Approvals → Damage (stock is only set aside)
 * until an Admin approves it. The confirmed quantity is kept in received_qty.
 */
function recordDamage(array $u, int $hid, int $iid, int $qty, string $place, string $at, string $notes): array
{
    $approved = false;
    q("INSERT INTO ha_issues (kind, status, hostel_id, item_id, qty, place, at, notes, received_qty, created_by, created_at, decided_by, decided_at)
       VALUES ('damage', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
        $approved ? 'done' : 'pending', $hid, $iid, $qty, $place, $at, $notes, $approved ? $qty : null, $u['id'], nowMs(),
        $approved ? $u['id'] : null, $approved ? nowMs() : null,
    ]);
    $id = (int) db()->lastInsertId();
    if ($approved) {
        addTxn(['hostel_id' => $hid, 'item_id' => $iid, 'kind' => 'damaged', 'dir' => -1, 'qty' => $qty, 'issue_id' => $id, 'party' => $place, 'at' => $at, 'notes' => $notes], $u);
    }
    return issueRow($id);
}
/**
 * Adds one stock-in entry (Purchase or Opening). Returns an error message, or '' when fine.
 * $checkOnly validates without saving; $notify sends the Telegram message for purchases.
 */
function stockAdd(array $u, array $b, bool $checkOnly = false, bool $notify = true): string
{
    $hid = idOrNull($b['hostelId'] ?? null);
    $iid = idOrNull($b['itemId'] ?? null);
    $kind = ($b['kind'] ?? '') === 'opening' ? 'opening' : 'in';
    $qty = int0($b['qty'] ?? 0);
    $loc = hostelRow($hid);
    if (!$loc) {
        return 'Choose a unit or warehouse.';
    }
    if (!canHostel($u, $hid)) {
        return "You don't look after {$loc['name']}.";
    }
    $item = itemRow($iid);
    if (!$item) {
        return 'Choose an item.';
    }
    if ($qty <= 0 || $qty > 1000000) {
        return 'Enter a quantity greater than 0.';
    }
    if ($kind === 'in' && str($b['notes'] ?? '') === '') {
        return 'Enter notes for a purchase (bill number, where it came from…).';
    }
    if ($kind === 'opening' && (int) q("SELECT COUNT(*) FROM ha_txns WHERE hostel_id=? AND item_id=? AND kind='opening'", [$hid, $iid])->fetchColumn() > 0) {
        return "Opening stock of {$item['name']} is already entered for {$loc['name']}. Add more as a Purchase instead.";
    }
    $warr = itemWarranty($iid);
    $expiry = dateOr($b['warrantyExpiry'] ?? '', null);
    if ($warr && $qty !== 1) {
        return "{$item['name']} has a warranty: enter one piece (Qty 1) per Serial No.";
    }
    if ($warr && str($b['warrantyNo'] ?? '', 80) === '') {
        return "{$item['name']} has a warranty: enter the Serial No.";
    }
    if ($warr && !$expiry) {
        return "{$item['name']} has a warranty: enter the warranty expiry date.";
    }
    if ($checkOnly) {
        return '';
    }
    $at = dateOr($b['at'] ?? '', today());
    $party = str($b['party'] ?? '', 160);
    $notes = str($b['notes'] ?? '', 500);
    addTxn(['hostel_id' => $hid, 'item_id' => $iid, 'kind' => $kind, 'dir' => 1, 'qty' => $qty, 'party' => $party, 'at' => $at, 'notes' => $notes], $u);
    if ($warr) {
        $tid = (int) db()->lastInsertId();
        q("INSERT INTO ha_warranties (hostel_id, category_id, item_id, txn_id, title, warranty_no, supplier, qty, start_at, expiry_at, notes, created_by, created_at) VALUES (?, ?, ?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?)",
            [$hid, $item['category_id'], $iid, $tid, str($b['warrantyNo'] ?? '', 80), $party, $qty, dateOr($b['warrantyStart'] ?? '', $at), $expiry, $notes, $u['id'], nowMs()]);
    }
    if ($kind === 'in' && $notify) {
        notifyStockIn($hid, $iid, $qty, $party, $notes, $u);
    }
    return '';
}
/** True when the item's category is marked "Warranty applicable". */
function itemWarranty(int $iid): bool
{
    return (bool) q("SELECT c.warranty FROM ha_items i JOIN ha_categories c ON c.id = i.category_id WHERE i.id = ?", [$iid])->fetchColumn();
}
function lockHostel(int $hid): void
{
    // Serialises stock changes for one hostel so two issues can't both spend the same stock.
    q("SELECT id FROM ha_hostels WHERE id = ? FOR UPDATE", [$hid]);
}
function addTxn(array $t, array $u): void
{
    q("INSERT INTO ha_txns (hostel_id, item_id, kind, dir, qty, issue_id, other_hostel_id, party, at, notes, created_by, created_at, sample)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
        $t['hostel_id'], $t['item_id'], $t['kind'], $t['dir'], $t['qty'], $t['issue_id'] ?? null, $t['other_hostel_id'] ?? null,
        $t['party'] ?? '', $t['at'], $t['notes'] ?? '', $u['id'] ?: null, nowMs(), $t['sample'] ?? 0,
    ]);
}
function hostelRow(?int $id): ?array
{
    return $id ? (q("SELECT * FROM ha_hostels WHERE id = ?", [$id])->fetch() ?: null) : null;
}
function itemRow(?int $id): ?array
{
    return $id ? (q("SELECT * FROM ha_items WHERE id = ?", [$id])->fetch() ?: null) : null;
}
function issueNo(array $i): string
{
    return ['transfer' => 'TR', 'repair' => 'RP', 'damage' => 'DM'][$i['kind']] . '-' . str_pad((string) $i['id'], 5, '0', STR_PAD_LEFT);
}
function issueRow(int $id): array
{
    $i = q("SELECT * FROM ha_issues WHERE id = ?", [$id])->fetch();
    if (!$i) {
        fail('That issue no longer exists.');
    }
    return $i;
}

/* ---------- row shapes for the app ---------- */
/** Clear message when a master name (or user email) is already taken, saying where to find the existing one. */
function dupCheck(string $coll, ?int $id, array $d): void
{
    $map = [
        'hostels' => ['ha_hostels', 'name', 'A unit', 'Masters → Units', true],
        'foundations' => ['ha_foundations', 'name', 'A foundation', 'Masters → Foundations', true],
        'items' => ['ha_items', 'name', 'An item', 'Masters → Items', true],
        'categories' => ['ha_categories', 'name', 'A category', 'Masters → Categories', false],
        'units' => ['ha_units', 'name', 'A measuring unit', 'Masters → Measuring units', false],
        'damagePlaces' => ['ha_damage_places', 'name', 'A damage destination', 'Masters → Damage destinations', false],
        'users' => ['ha_users', 'email', 'A user', 'Masters → Users', true],
    ];
    if (!isset($map[$coll])) {
        return;
    }
    [$table, $col, $what, $where, $hasActive] = $map[$coll];
    $val = trim((string) ($d[$col] ?? ''));
    if ($val === '') {
        return;
    }
    $row = q("SELECT * FROM $table WHERE $col = ? AND id <> ?", [$val, $id ?? 0])->fetch();
    if (!$row) {
        return;
    }
    $label = $col === 'email' ? 'with the email "' . $row[$col] . '"' : 'named "' . $row[$col] . '"';
    $inactive = $hasActive && isset($row['active']) && !(int) $row['active'];
    fail("$what $label already exists" . ($inactive ? ' but is inactive. Open it in ' . $where . ', edit it and tick Active to use it again.' : '. You can find it in ' . $where . '.'));
}

function cronKey(): string
{
    return substr(hash_hmac('sha256', 'hostel-assets-cron', DB_PASS), 0, 32);
}

function shapeIssue(array $r): array
{
    return [
        'id' => (int) $r['id'], 'no' => issueNo($r), 'kind' => $r['kind'], 'status' => $r['status'],
        'hostelId' => (int) $r['hostel_id'], 'toHostelId' => $r['to_hostel_id'] === null ? null : (int) $r['to_hostel_id'],
        'itemId' => (int) $r['item_id'], 'qty' => (int) $r['qty'], 'place' => $r['place'], 'at' => $r['at'],
        'expectedAt' => $r['expected_at'], 'notes' => $r['notes'], 'returnedQty' => (int) $r['returned_qty'],
        'repairType' => $r['repair_type'] ?? '', 'scrappedQty' => (int) $r['scrapped_qty'], 'receivedQty' => $r['received_qty'] === null ? null : (int) $r['received_qty'], 'createdBy' => $r['created_by'] === null ? null : (int) $r['created_by'],
        'createdAt' => (int) $r['created_at'], 'decidedBy' => $r['decided_by'] === null ? null : (int) $r['decided_by'],
        'decidedAt' => $r['decided_at'] === null ? null : (int) $r['decided_at'], 'decisionNote' => $r['decision_note'],
    ];
}

$action = $_GET['a'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST' && ($_SERVER['HTTP_X_REQ'] ?? '') !== '1') {
    fail('Missing request header.', 403, 'forbidden');
}

try {
    ensureSchema(db());

    switch ($action) {
        /* ===== sign-in ===== */
        case 'status':
            out(['needsSetup' => loginCount() === 0, 'user' => publicMe(me())]);

        case 'setup': {
            if (loginCount() > 0) {
                fail('Setup is already done. Sign in instead.', 403, 'forbidden');
            }
            $b = body();
            $name = str($b['name'] ?? '', 120);
            $email = strtolower(str($b['email'] ?? '', 190));
            $pass = (string) ($b['password'] ?? '');
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
                fail('Enter a name, a valid email and a password of at least 8 characters.');
            }
            q("INSERT INTO ha_users (name, email, role, active, password_hash) VALUES (?, ?, 'Admin', 1, ?)", [$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['uid'] = (int) db()->lastInsertId();
            out(['user' => publicMe(me())]);
        }

        case 'login': {
            $b = body();
            $email = strtolower(str($b['email'] ?? '', 190));
            $pass = (string) ($b['password'] ?? '');
            $u = q("SELECT * FROM ha_users WHERE email = ? AND password_hash IS NOT NULL AND active = 1", [$email])->fetch();
            if (!$u || !password_verify($pass, $u['password_hash'])) {
                usleep(800000);
                fail('Email or password is wrong.', 401, 'invalid_login');
            }
            if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                q("UPDATE ha_users SET password_hash = ? WHERE id = ?", [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
            }
            session_regenerate_id(true);
            $_SESSION['uid'] = (int) $u['id'];
            out(['user' => publicMe(me())]);
        }

        case 'logout':
            $_SESSION = [];
            session_destroy();
            out(['ok' => true]);

        /* ===== everything the app shows, scoped to the user ===== */
        case 'data': {
            $u = requireUser();
            $admin = isAdmin($u);
            [$inH, $hp] = scopeSql($u, 'hostel_id');
            $res = ['me' => publicMe($u)];
            $res['org'] = settings()['org_name'] ?? '';
            $res['foundations'] = array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'active' => (bool) $r['active']] + ($admin ? ['tgChat' => $r['tg_chat']] : []),
                db()->query("SELECT * FROM ha_foundations ORDER BY name")->fetchAll());
            // Every location (Units and Central Warehouses), so names show and transfers can go anywhere.
            $res['hostels'] = array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'code' => $r['code'], 'address' => $r['address'], 'phone' => $r['phone'], 'active' => (bool) $r['active'],
                'foundationId' => $r['foundation_id'] === null ? null : (int) $r['foundation_id'], 'kind' => $r['kind'], 'unitType' => $r['unit_type']] + ($admin ? ['tgChat' => $r['tg_chat']] : []),
                db()->query("SELECT * FROM ha_hostels ORDER BY kind = 'unit', name")->fetchAll());
            $res['categories'] = array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'warranty' => (bool) $r['warranty']], db()->query("SELECT * FROM ha_categories ORDER BY name")->fetchAll());
            $res['units'] = array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name']], db()->query("SELECT * FROM ha_units ORDER BY name")->fetchAll());
            $res['damagePlaces'] = array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name']], db()->query("SELECT * FROM ha_damage_places ORDER BY name")->fetchAll());
            $res['items'] = array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'categoryId' => $r['category_id'] === null ? null : (int) $r['category_id'], 'unit' => $r['unit'], 'notes' => $r['notes'], 'active' => (bool) $r['active']],
                db()->query("SELECT * FROM ha_items ORDER BY name")->fetchAll());
            $res['users'] = array_map(function ($r) use ($admin) {
                $o = ['id' => (int) $r['id'], 'name' => $r['name'], 'role' => $r['role'], 'hostelId' => $r['hostel_id'] === null ? null : (int) $r['hostel_id'],
                    'foundationId' => $r['foundation_id'] === null ? null : (int) $r['foundation_id']];
                if ($admin) {
                    $o += ['email' => $r['email'], 'phone' => $r['phone'], 'active' => (bool) $r['active'], 'hasLogin' => $r['password_hash'] !== null];
                }
                return $o;
            }, db()->query("SELECT * FROM ha_users ORDER BY name")->fetchAll());

            $res['txns'] = array_map(fn($r) => [
                'id' => (int) $r['id'], 'hostelId' => (int) $r['hostel_id'], 'itemId' => (int) $r['item_id'], 'kind' => $r['kind'],
                'dir' => (int) $r['dir'], 'qty' => (int) $r['qty'], 'issueId' => $r['issue_id'] === null ? null : (int) $r['issue_id'],
                'otherHostelId' => $r['other_hostel_id'] === null ? null : (int) $r['other_hostel_id'], 'party' => $r['party'],
                'at' => $r['at'], 'notes' => $r['notes'], 'createdBy' => $r['created_by'] === null ? null : (int) $r['created_by'], 'createdAt' => (int) $r['created_at'],
            ], (seesAll($u) ? db()->query("SELECT * FROM ha_txns ORDER BY at, id") : q("SELECT * FROM ha_txns WHERE $inH ORDER BY at, id", $hp))->fetchAll());

            [$inTo] = scopeSql($u, 'to_hostel_id');
            $res['issues'] = array_map('shapeIssue', (seesAll($u)
                ? db()->query("SELECT * FROM ha_issues ORDER BY id DESC")
                : q("SELECT * FROM ha_issues WHERE $inH OR $inTo ORDER BY id DESC", [...$hp, ...$hp]))->fetchAll());

            $res['warranties'] = array_map(fn($r) => [
                'id' => (int) $r['id'], 'hostelId' => $r['hostel_id'] === null ? null : (int) $r['hostel_id'],
                'categoryId' => $r['category_id'] === null ? null : (int) $r['category_id'], 'itemId' => $r['item_id'] === null ? null : (int) $r['item_id'],
                'title' => $r['title'], 'warrantyNo' => $r['warranty_no'], 'supplier' => $r['supplier'], 'qty' => $r['qty'] === null ? null : (int) $r['qty'],
                'startAt' => $r['start_at'], 'expiryAt' => $r['expiry_at'], 'notes' => $r['notes'], 'txnId' => $r['txn_id'] === null ? null : (int) $r['txn_id'],
            ], (seesAll($u) ? db()->query("SELECT * FROM ha_warranties ORDER BY expiry_at") : q("SELECT * FROM ha_warranties WHERE $inH ORDER BY expiry_at", $hp))->fetchAll());

            if ($admin) {
                $s = settings();
                $res['settings'] = [
                    'orgName' => $s['org_name'] ?? '',
                    'telegramOn' => ($s['telegram_on'] ?? '0') === '1', 'telegramChat' => $s['telegram_chat'] ?? '',
                    'telegramTokenSet' => ($s['telegram_token'] ?? '') !== '', 'appUrl' => $s['app_url'] ?? '',
                    'tgOff' => array_values(array_filter(explode(',', $s['tg_off'] ?? ''))), 'dailyTime' => $s['daily_time'] ?? '09:00',
                    'tgEvents' => TG_EVENTS, 'lastDaily' => $s['last_daily'] ?? '',
                    'cronCmd' => 'php ' . __DIR__ . '/cron.php', 'cronUrl' => rtrim(($s['app_url'] ?? '') !== '' ? $s['app_url'] : 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), '/') . '/api/?a=cron&key=' . cronKey(),
                ];
            }
            $res['today'] = today();
            // Daily Telegram messages go out on the first visit after the set time, after this reply is sent.
            register_shutdown_function(function () {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                } elseif (function_exists('litespeed_finish_request')) {
                    litespeed_finish_request();
                }
                try {
                    runDaily();
                } catch (Throwable $e) {
                    error_log('[hostel-assets] daily failed: ' . $e->getMessage());
                }
            });
            out($res);
        }

        /* ===== masters (Admin only) ===== */
        case 'master.save': {
            requireAdmin();
            $b = body();
            $coll = (string) ($b['coll'] ?? '');
            $id = idOrNull($b['id'] ?? null);
            $d = is_array($b['data'] ?? null) ? $b['data'] : [];
            dupCheck($coll, $id, $d);
            switch ($coll) {
                case 'hostels':
                    // Units (hostels and colleges). Central Warehouses are managed through their Foundation.
                    $name = str($d['name'] ?? '', 120);
                    if ($name === '') {
                        fail('Enter the unit name.');
                    }
                    if ($id && (hostelRow($id)['kind'] ?? '') === 'warehouse') {
                        fail('A Central Warehouse is renamed with its Foundation, in Masters → Foundations.');
                    }
                    $fid = idOrNull($d['foundationId'] ?? null);
                    if (!$fid || !q("SELECT 1 FROM ha_foundations WHERE id=?", [$fid])->fetchColumn()) {
                        fail('Choose the Foundation this unit belongs to.');
                    }
                    $type = in_array($d['unitType'] ?? '', UNIT_TYPES, true) ? $d['unitType'] : '';
                    if ($type === '') {
                        fail('Choose the unit type.');
                    }
                    $tg = tgChatOk($d['tgChat'] ?? '');
                    $vals = [$name, $fid, $type, str($d['address'] ?? ''), $tg, empty($d['active']) ? 0 : 1];
                    $id ? q("UPDATE ha_hostels SET name=?, foundation_id=?, unit_type=?, address=?, tg_chat=?, active=? WHERE id=?", [...$vals, $id])
                        : q("INSERT INTO ha_hostels (name, foundation_id, unit_type, address, tg_chat, active, kind) VALUES (?, ?, ?, ?, ?, ?, 'unit')", $vals);
                    break;
                case 'foundations':
                    $name = str($d['name'] ?? '', 120);
                    if ($name === '') {
                        fail('Enter the foundation name.');
                    }
                    $wh = warehouseName($name);
                    if (q("SELECT 1 FROM ha_hostels WHERE name=? AND NOT (kind='warehouse' AND foundation_id <=> ?)", [$wh, $id])->fetchColumn()) {
                        fail('A unit is already named "' . $wh . '". Rename it first.');
                    }
                    $active = empty($d['active']) ? 0 : 1;
                    $tg = tgChatOk($d['tgChat'] ?? '');
                    if ($id) {
                        q("UPDATE ha_foundations SET name=?, tg_chat=?, active=? WHERE id=?", [$name, $tg, $active, $id]);
                        q("UPDATE ha_hostels SET name=?, active=? WHERE kind='warehouse' AND foundation_id=?", [$wh, $active, $id]);
                    } else {
                        q("INSERT INTO ha_foundations (name, tg_chat, active) VALUES (?, ?, ?)", [$name, $tg, $active]);
                        $id = (int) db()->lastInsertId();
                        // Every Foundation gets its own Central Warehouse.
                        q("INSERT INTO ha_hostels (foundation_id, kind, unit_type, name, active) VALUES (?, 'warehouse', '', ?, ?)", [$id, $wh, $active]);
                    }
                    break;
                case 'items':
                    $name = str($d['name'] ?? '', 120);
                    if ($name === '') {
                        fail('Enter the item name.');
                    }
                    $vals = [$name, idOrNull($d['categoryId'] ?? null), str($d['unit'] ?? '', 20) ?: 'Nos', str($d['notes'] ?? ''), empty($d['active']) ? 0 : 1];
                    if ($id) {
                        q("UPDATE ha_items SET name=?, category_id=?, unit=?, notes=?, active=? WHERE id=?", [...$vals, $id]);
                    } else {
                        q("INSERT INTO ha_items (name, category_id, unit, notes, active) VALUES (?, ?, ?, ?, ?)", $vals);
                        $id = (int) db()->lastInsertId();
                    }
                    q("INSERT IGNORE INTO ha_units (name) VALUES (?)", [$vals[2]]);
                    break;
                case 'units':
                    $name = str($d['name'] ?? '', 20);
                    if ($name === '') {
                        fail('Enter the unit, e.g. Nos or Sets.');
                    }
                    if ($id) {
                        // Renaming a unit renames it on every item that uses it.
                        $old = q("SELECT name FROM ha_units WHERE id=?", [$id])->fetchColumn();
                        q("UPDATE ha_units SET name=? WHERE id=?", [$name, $id]);
                        if ($old !== false) {
                            q("UPDATE ha_items SET unit=? WHERE unit=?", [$name, $old]);
                        }
                    } else {
                        q("INSERT INTO ha_units (name) VALUES (?)", [$name]);
                    }
                    break;
                case 'categories':
                    $name = str($d['name'] ?? '', 80);
                    if ($name === '') {
                        fail('Enter a name.');
                    }
                    $w = empty($d['warranty']) ? 0 : 1;
                    $id ? q("UPDATE ha_categories SET name=?, warranty=? WHERE id=?", [$name, $w, $id]) : q("INSERT INTO ha_categories (name, warranty) VALUES (?, ?)", [$name, $w]);
                    break;
                case 'damagePlaces':
                    $t = $coll === 'categories' ? 'ha_categories' : 'ha_damage_places';
                    $name = str($d['name'] ?? '', 80);
                    if ($name === '') {
                        fail('Enter a name.');
                    }
                    $id ? q("UPDATE $t SET name=? WHERE id=?", [$name, $id]) : q("INSERT INTO $t (name) VALUES (?)", [$name]);
                    break;
                case 'users':
                    $name = str($d['name'] ?? '', 120);
                    $email = strtolower(str($d['email'] ?? '', 190));
                    $role = in_array($d['role'] ?? '', ROLES, true) ? $d['role'] : 'Unit';
                    $hid = $role === 'Unit' ? idOrNull($d['hostelId'] ?? null) : null;
                    $fid = $role === 'Foundation' ? idOrNull($d['foundationId'] ?? null) : null;
                    $active = empty($d['active']) ? 0 : 1;
                    $pass = (string) ($d['password'] ?? '');
                    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        fail('Enter a name and a valid email.');
                    }
                    if ($role === 'Unit' && (!$hid || (hostelRow($hid)['kind'] ?? '') !== 'unit')) {
                        fail('Choose the unit this person looks after.');
                    }
                    if ($role === 'Foundation' && (!$fid || !q("SELECT 1 FROM ha_foundations WHERE id=?", [$fid])->fetchColumn())) {
                        fail('Choose the Foundation this person looks after.');
                    }
                    if ($pass !== '' && strlen($pass) < 8) {
                        fail('Password must be at least 8 characters.');
                    }
                    if (!$id && $pass === '') {
                        fail('Set a password so this person can sign in.');
                    }
                    if ($id && $id === me()['id'] && ($role !== 'Admin' || !$active)) {
                        fail('You cannot remove your own Admin access.');
                    }
                    $vals = [$name, $email, $role, $hid, $fid, str($d['phone'] ?? '', 40), $active];
                    if ($id) {
                        q("UPDATE ha_users SET name=?, email=?, role=?, hostel_id=?, foundation_id=?, phone=?, active=? WHERE id=?", [...$vals, $id]);
                    } else {
                        q("INSERT INTO ha_users (name, email, role, hostel_id, foundation_id, phone, active) VALUES (?, ?, ?, ?, ?, ?, ?)", $vals);
                        $id = (int) db()->lastInsertId();
                    }
                    if ($pass !== '') {
                        q("UPDATE ha_users SET password_hash=? WHERE id=?", [password_hash($pass, PASSWORD_DEFAULT), $id]);
                    }
                    break;
                default:
                    fail('Unknown master.');
            }
            out(['ok' => true, 'id' => $id ?: (int) db()->lastInsertId()]);
        }

        case 'master.delete': {
            $u = requireAdmin();
            $b = body();
            $coll = (string) ($b['coll'] ?? '');
            $id = idOrNull($b['id'] ?? null) ?? 0;
            $used = function (string $sql) use ($id): bool {
                return (bool) q($sql, [$id])->fetchColumn();
            };
            switch ($coll) {
                case 'hostels':
                    if ((hostelRow($id)['kind'] ?? '') === 'warehouse') {
                        fail('A Central Warehouse is removed with its Foundation.');
                    }
                    if ($used("SELECT 1 FROM ha_txns WHERE ? IN (hostel_id, other_hostel_id) LIMIT 1") || $used("SELECT 1 FROM ha_issues WHERE ? IN (hostel_id, to_hostel_id) LIMIT 1")) {
                        fail('This unit has stock history, so it can\'t be deleted. Mark it inactive instead.');
                    }
                    if ($used("SELECT 1 FROM ha_users WHERE hostel_id=? LIMIT 1")) {
                        fail('Some users are linked to this unit. Move them to another unit first.');
                    }
                    q("DELETE FROM ha_hostels WHERE id=?", [$id]);
                    break;
                case 'foundations':
                    if ($used("SELECT 1 FROM ha_hostels WHERE foundation_id=? AND kind='unit' LIMIT 1")) {
                        fail('This Foundation still has units. Move or delete them first.');
                    }
                    if ($used("SELECT 1 FROM ha_users WHERE foundation_id=? LIMIT 1")) {
                        fail('Some users are linked to this Foundation. Move them first.');
                    }
                    $wid = (int) q("SELECT id FROM ha_hostels WHERE kind='warehouse' AND foundation_id=?", [$id])->fetchColumn();
                    if ($wid && (q("SELECT 1 FROM ha_txns WHERE ? IN (hostel_id, other_hostel_id) LIMIT 1", [$wid])->fetchColumn() || q("SELECT 1 FROM ha_issues WHERE ? IN (hostel_id, to_hostel_id) LIMIT 1", [$wid])->fetchColumn())) {
                        fail('Its Central Warehouse has stock history, so this Foundation can\'t be deleted. Mark it inactive instead.');
                    }
                    q("DELETE FROM ha_hostels WHERE kind='warehouse' AND foundation_id=?", [$id]);
                    q("DELETE FROM ha_foundations WHERE id=?", [$id]);
                    break;
                case 'items':
                    if ($used("SELECT 1 FROM ha_txns WHERE item_id=? LIMIT 1") || $used("SELECT 1 FROM ha_issues WHERE item_id=? LIMIT 1")) {
                        fail('This item has stock history, so it can\'t be deleted. Mark it inactive instead.');
                    }
                    q("DELETE FROM ha_items WHERE id=?", [$id]);
                    break;
                case 'categories':
                    if ($used("SELECT 1 FROM ha_items WHERE category_id=? LIMIT 1")) {
                        fail('Some items use this category. Change them first.');
                    }
                    q("DELETE FROM ha_categories WHERE id=?", [$id]);
                    break;
                case 'damagePlaces':
                    q("DELETE FROM ha_damage_places WHERE id=?", [$id]);
                    break;
                case 'units':
                    if ($used("SELECT 1 FROM ha_items i JOIN ha_units u ON u.name = i.unit WHERE u.id=? LIMIT 1")) {
                        fail('Some items use this unit. Change them first.');
                    }
                    q("DELETE FROM ha_units WHERE id=?", [$id]);
                    break;
                case 'users':
                    if ($id === $u['id']) {
                        fail('You cannot delete your own login.');
                    }
                    q("DELETE FROM ha_users WHERE id=?", [$id]);
                    break;
                default:
                    fail('Unknown master.');
            }
            out(['ok' => true]);
        }

        /* ===== stock in: opening stock and items received ===== */
        case 'stock.add': {
            // Purchase (kind 'in') or Opening stock. Items in a warranty category also record their warranty.
            $u = requireUser();
            $b = body();
            $err = stockAdd($u, $b);
            if ($err) {
                fail($err);
            }
            out(['ok' => true]);
        }

        case 'stock.bulk': {
            // Opening stock from an uploaded sheet. With check=1 nothing is saved: every row is checked and
            // its problems returned. Otherwise all rows are saved together, or none if any row has a problem.
            $u = requireUser();
            $b = body();
            $rows = is_array($b['rows'] ?? null) ? array_slice($b['rows'], 0, 2000) : [];
            if (!$rows) {
                fail('The file has no rows to import.');
            }
            $locs = [];
            foreach (db()->query("SELECT id, name FROM ha_hostels WHERE active = 1")->fetchAll() as $r) {
                $locs[mb_strtolower(trim($r['name']))] = (int) $r['id'];
            }
            $items = [];
            foreach (db()->query("SELECT id, name FROM ha_items WHERE active = 1")->fetchAll() as $r) {
                $items[mb_strtolower(trim($r['name']))] = (int) $r['id'];
            }
            $prep = [];
            $errors = [];
            $seen = [];
            foreach ($rows as $n => $r) {
                $line = $n + 2; // row 1 is the header
                $hid = $locs[mb_strtolower(trim((string) ($r['location'] ?? '')))] ?? null;
                $iid = $items[mb_strtolower(trim((string) ($r['item'] ?? '')))] ?? null;
                $e = [];
                if (!$hid) {
                    $e[] = 'unit / warehouse "' . str($r['location'] ?? '', 60) . '" not found';
                }
                if (!$iid) {
                    $e[] = 'item "' . str($r['item'] ?? '', 60) . '" not found';
                }
                if ($hid && $iid) {
                    $key = "$hid-$iid";
                    if (isset($seen[$key])) {
                        $e[] = 'same unit and item as row ' . $seen[$key];
                    }
                    $seen[$key] = $line;
                    $d = ['kind' => 'opening', 'hostelId' => $hid, 'itemId' => $iid, 'qty' => $r['qty'] ?? '', 'at' => $r['date'] ?? '', 'notes' => $r['notes'] ?? '',
                        'party' => $r['supplier'] ?? '', 'warrantyNo' => $r['warrantyNo'] ?? '', 'warrantyStart' => $r['warrantyStart'] ?? '', 'warrantyExpiry' => $r['warrantyExpiry'] ?? ''];
                    $msg = stockAdd($u, $d, true);
                    if ($msg) {
                        $e[] = $msg;
                    }
                    $prep[] = $d;
                }
                if ($e) {
                    $errors[] = ['row' => $line, 'error' => implode('; ', $e)];
                }
            }
            if ($errors || !empty($b['check'])) {
                out(['ok' => !$errors, 'rows' => count($rows), 'errors' => $errors]);
            }
            db()->beginTransaction();
            foreach ($prep as $d) {
                $msg = stockAdd($u, $d, false, false);
                if ($msg) {
                    db()->rollBack();
                    fail($msg);
                }
            }
            db()->commit();
            out(['ok' => true, 'added' => count($prep)]);
        }

        case 'txn.delete': {
            // Admin correction for a wrong opening / received entry. Issue-linked entries are removed by deleting the issue.
            $u = requireAdmin();
            $id = idOrNull(body()['id'] ?? null) ?? 0;
            $t = q("SELECT * FROM ha_txns WHERE id=?", [$id])->fetch();
            if (!$t) {
                fail('That entry no longer exists.');
            }
            if (!in_array($t['kind'], ['opening', 'in'], true)) {
                fail('This entry belongs to an issue. Delete the issue instead.');
            }
            db()->beginTransaction();
            lockHostel((int) $t['hostel_id']);
            $after = onHand((int) $t['hostel_id'], (int) $t['item_id']) - (int) $t['qty'];
            if ($after < 0) {
                db()->rollBack();
                fail('Deleting this would make stock negative, because some of it has already been issued.');
            }
            q("DELETE FROM ha_txns WHERE id=?", [$id]);
            db()->commit();
            out(['ok' => true]);
        }

        /* ===== issue: transfer to another hostel, send for repair, or mark damaged ===== */
        case 'issue.create': {
            $u = requireUser();
            $b = body();
            $kind = (string) ($b['kind'] ?? '');
            $hid = idOrNull($b['hostelId'] ?? null);
            $iid = idOrNull($b['itemId'] ?? null);
            $qty = int0($b['qty'] ?? 0);
            $to = idOrNull($b['toHostelId'] ?? null);
            $at = dateOr($b['at'] ?? '', today());
            $place = str($b['place'] ?? '', 160);
            $notes = str($b['notes'] ?? '', 500);
            if (!in_array($kind, ['transfer', 'repair', 'damage'], true)) {
                fail('Choose the issue type.');
            }
            if (!canHostel($u, $hid) || !hostelRow($hid)) {
                fail('Choose the unit or warehouse the items are leaving.');
            }
            $item = itemRow($iid);
            if (!$item) {
                fail('Choose an item.');
            }
            if ($qty <= 0) {
                fail('Enter a quantity greater than 0.');
            }
            // Items with a warranty are issued one piece at a time.
            if ($qty > 1 && itemWarranty($iid)) {
                fail("{$item['name']} has a warranty, so it is issued one piece at a time. Enter quantity 1.");
            }
            $rtype = $kind === 'repair' ? (($b['repairType'] ?? '') === 'Internal' ? 'Internal' : 'External') : '';
            if ($kind === 'repair' && $place === '') {
                fail($rtype === 'Internal' ? 'Enter who is repairing the items.' : 'Enter who the items are sent to for repair.');
            }
            if ($kind === 'repair' && !dateOr($b['expectedAt'] ?? '', null)) {
                fail('Enter the expected return date.');
            }
            if ($kind === 'damage' && $place === '') {
                fail('Choose where the damaged items are sent.');
            }
            if ($kind === 'transfer') {
                $toRow = hostelRow($to);
                if (!$toRow || !(int) $toRow['active']) {
                    fail('Choose where the items are going.');
                }
                if ($to === $hid) {
                    fail('Send to a different unit or warehouse.');
                }
            }
            db()->beginTransaction();
            lockHostel($hid);
            $avail = onHand($hid, $iid) - pendingOut($hid, $iid);
            if ($qty > $avail) {
                db()->rollBack();
                fail("Only $avail {$item['unit']} of {$item['name']} available to issue here.");
            }
            if ($kind === 'damage') {
                $issue = recordDamage($u, $hid, $iid, $qty, $place, $at, $notes);
                db()->commit();
                if ($issue['status'] === 'pending') {
                    notifyIssue('damage_reported', $issue, $u);
                }
                out(['ok' => true, 'issue' => shapeIssue($issue)]);
            }
            $status = ['transfer' => 'pending', 'repair' => 'at_repair'][$kind];
            q("INSERT INTO ha_issues (kind, status, hostel_id, to_hostel_id, item_id, qty, place, repair_type, at, expected_at, notes, created_by, created_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
                $kind, $status, $hid, $kind === 'transfer' ? $to : null, $iid, $qty, $kind === 'transfer' ? '' : $place, $rtype, $at,
                $kind === 'repair' ? dateOr($b['expectedAt'] ?? '', null) : null, $notes, $u['id'], nowMs(),
            ]);
            $issueId = (int) db()->lastInsertId();
            if ($kind === 'repair') {
                addTxn(['hostel_id' => $hid, 'item_id' => $iid, 'kind' => 'repair_out', 'dir' => -1, 'qty' => $qty, 'issue_id' => $issueId, 'party' => $place, 'at' => $at, 'notes' => $notes], $u);
            }
            db()->commit();
            $issue = issueRow($issueId);
            notifyIssue($kind === 'transfer' ? 'transfer_sent' : 'repair_sent', $issue, $u);
            out(['ok' => true, 'issue' => shapeIssue($issue)]);
        }

        case 'issue.receive': {
            // The receiving hostel updates how many actually arrived. Only the received quantity moves:
            // it leaves the sending hostel and is added to the receiving one. Any shortfall stays on the
            // sending hostel's books (they can mark it damaged if it was lost). 0 received = not received.
            $u = requireUser();
            $b = body();
            $i = issueRow(idOrNull($b['id'] ?? null) ?? 0);
            $recvQty = int0($b['receivedQty'] ?? -1);
            $note = str($b['note'] ?? '', 500);
            if ($i['kind'] !== 'transfer' || $i['status'] !== 'pending') {
                fail('This transfer has already been updated.');
            }
            if (!canHostel($u, (int) $i['to_hostel_id'])) {
                fail('Only the receiving unit or warehouse can update this transfer.', 403, 'forbidden');
            }
            if ($recvQty < 0 || $recvQty > (int) $i['qty']) {
                fail('Received quantity must be between 0 and ' . (int) $i['qty'] . '.');
            }
            if ($recvQty < (int) $i['qty'] && $note === '') {
                fail('Fewer items arrived than were sent. Add a note explaining the difference.');
            }
            db()->beginTransaction();
            lockHostel((int) $i['hostel_id']);
            $i = issueRow((int) $i['id']);
            if ($i['status'] !== 'pending') {
                db()->rollBack();
                fail('This transfer has already been updated.');
            }
            if ($recvQty > 0) {
                $avail = onHand((int) $i['hostel_id'], (int) $i['item_id']) - pendingOut((int) $i['hostel_id'], (int) $i['item_id'], (int) $i['id']);
                if ($recvQty > $avail) {
                    db()->rollBack();
                    fail("The sender now has only $avail in stock, so $recvQty can't be received. Ask them to cancel and resend.");
                }
                $common = ['item_id' => (int) $i['item_id'], 'qty' => $recvQty, 'issue_id' => (int) $i['id'], 'at' => today(), 'notes' => $i['notes']];
                addTxn($common + ['hostel_id' => (int) $i['hostel_id'], 'kind' => 'transfer_out', 'dir' => -1, 'other_hostel_id' => (int) $i['to_hostel_id']], $u);
                addTxn($common + ['hostel_id' => (int) $i['to_hostel_id'], 'kind' => 'transfer_in', 'dir' => 1, 'other_hostel_id' => (int) $i['hostel_id']], $u);
            }
            $status = $recvQty === 0 ? 'rejected' : ($recvQty < (int) $i['qty'] ? 'short' : 'received');
            q("UPDATE ha_issues SET status=?, received_qty=?, decided_by=?, decided_at=?, decision_note=? WHERE id=?", [$status, $recvQty, $u['id'], nowMs(), $note, $i['id']]);
            db()->commit();
            notifyIssue('transfer_received', issueRow((int) $i['id']), $u);
            out(['ok' => true]);
        }

        case 'damage.decide': {
            // Admin, Organization or the location's Foundation approves damage (all or fewer than reported) or rejects it.
            $u = requireUser();
            $b = body();
            $i = issueRow(idOrNull($b['id'] ?? null) ?? 0);
            if (!canApprove($u, (int) $i['hostel_id'])) {
                fail('Only an Admin, Organization or the Foundation user can approve damage.', 403, 'forbidden');
            }
            $approve = ($b['decision'] ?? '') === 'approve';
            $note = str($b['note'] ?? '', 500);
            if ($i['kind'] !== 'damage' || $i['status'] !== 'pending') {
                fail('This damage entry has already been decided.');
            }
            if (!$approve && $note === '') {
                fail('Give a reason for rejecting.');
            }
            db()->beginTransaction();
            lockHostel((int) $i['hostel_id']);
            $i = issueRow((int) $i['id']);
            if ($i['status'] !== 'pending') {
                db()->rollBack();
                fail('This damage entry has already been decided.');
            }
            $qty = 0;
            if ($approve) {
                $qty = int0($b['qty'] ?? $i['qty']);
                if ($qty <= 0 || $qty > (int) $i['qty']) {
                    db()->rollBack();
                    fail('Approved quantity must be between 1 and ' . (int) $i['qty'] . '.');
                }
                $avail = onHand((int) $i['hostel_id'], (int) $i['item_id']) - pendingOut((int) $i['hostel_id'], (int) $i['item_id'], (int) $i['id']);
                if ($qty > $avail) {
                    db()->rollBack();
                    fail("Only $avail are now in stock there, so $qty can't be written off.");
                }
                addTxn(['hostel_id' => (int) $i['hostel_id'], 'item_id' => (int) $i['item_id'], 'kind' => 'damaged', 'dir' => -1, 'qty' => $qty,
                    'issue_id' => (int) $i['id'], 'party' => $i['place'], 'at' => today(), 'notes' => $i['notes']], $u);
            }
            q("UPDATE ha_issues SET status=?, received_qty=?, decided_by=?, decided_at=?, decision_note=? WHERE id=?",
                [$approve ? 'done' : 'rejected', $approve ? $qty : 0, $u['id'], nowMs(), $note, $i['id']]);
            db()->commit();
            notifyIssue($approve ? 'damage_approved' : 'damage_rejected', issueRow((int) $i['id']), $u);
            out(['ok' => true]);
        }

        case 'issue.cancel': {
            // The sending hostel withdraws a transfer not yet received, or damage not yet approved.
            $u = requireUser();
            $i = issueRow(idOrNull(body()['id'] ?? null) ?? 0);
            if (!in_array($i['kind'], ['transfer', 'damage'], true) || $i['status'] !== 'pending') {
                fail('Only a transfer or damage entry that is still waiting can be cancelled.');
            }
            if (!canHostel($u, (int) $i['hostel_id'])) {
                fail('Only the unit or warehouse that entered this can cancel it.', 403, 'forbidden');
            }
            q("UPDATE ha_issues SET status='cancelled', decided_by=?, decided_at=? WHERE id=? AND status='pending'", [$u['id'], nowMs(), $i['id']]);
            if ($i['kind'] === 'transfer') {
                notifyIssue('transfer_cancelled', $i, $u);
            }
            out(['ok' => true]);
        }

        case 'issue.delete': {
            // Admin removes an issue entered by mistake, together with all its stock entries.
            $u = requireAdmin();
            $i = issueRow(idOrNull(body()['id'] ?? null) ?? 0);
            db()->beginTransaction();
            lockHostel((int) $i['hostel_id']);
            if ($i['kind'] === 'transfer' && in_array($i['status'], ['received', 'short', 'approved'], true)) {
                db()->rollBack();
                fail('This transfer has been received, so it can\'t be deleted.');
            }
            q("DELETE FROM ha_txns WHERE issue_id=?", [$i['id']]);
            q("DELETE FROM ha_issues WHERE id=?", [$i['id']]);
            db()->commit();
            out(['ok' => true]);
        }

        case 'repair.return': {
            // Items back from repair: okQty go back into stock; scrapQty were not repairable and count as damaged.
            $u = requireUser();
            $b = body();
            $i = issueRow(idOrNull($b['id'] ?? null) ?? 0);
            $ok = int0($b['okQty'] ?? 0);
            $scrap = int0($b['scrapQty'] ?? 0);
            $at = dateOr($b['at'] ?? '', today());
            $notes = str($b['notes'] ?? '', 500);
            if ($i['kind'] !== 'repair' || !in_array($i['status'], ['at_repair', 'partly_returned'], true)) {
                fail('This repair is already closed.');
            }
            if (!canHostel($u, (int) $i['hostel_id'])) {
                fail('Only the unit or warehouse that sent these items can record their return.', 403, 'forbidden');
            }
            $outstanding = (int) $i['qty'] - (int) $i['returned_qty'] - (int) $i['scrapped_qty'];
            if ($ok < 0 || $scrap < 0 || $ok + $scrap === 0) {
                fail('Enter how many came back repaired, or how many could not be repaired.');
            }
            if ($ok + $scrap > $outstanding) {
                fail("Only $outstanding are still at repair.");
            }
            db()->beginTransaction();
            lockHostel((int) $i['hostel_id']);
            $base = ['hostel_id' => (int) $i['hostel_id'], 'item_id' => (int) $i['item_id'], 'issue_id' => (int) $i['id'], 'at' => $at];
            if ($ok > 0) {
                addTxn($base + ['kind' => 'repair_return', 'dir' => 1, 'qty' => $ok, 'party' => $i['place'], 'notes' => $notes], $u);
            }
            $dmg = null;
            if ($scrap > 0) {
                // They come back on the books from repair, then are written off as damage, which waits for an
                // Admin's approval like any damage report, so every column still adds up.
                addTxn($base + ['kind' => 'repair_return', 'dir' => 1, 'qty' => $scrap, 'party' => $i['place'], 'notes' => 'Not repairable' . ($notes ? " — $notes" : '')], $u);
                $dmg = recordDamage($u, (int) $i['hostel_id'], (int) $i['item_id'], $scrap, str($b['place'] ?? '', 160), $at,
                    'Not repairable after repair ' . issueNo($i) . ($notes ? " — $notes" : ''));
            }
            $left = $outstanding - $ok - $scrap;
            q("UPDATE ha_issues SET returned_qty = returned_qty + ?, scrapped_qty = scrapped_qty + ?, status = ? WHERE id = ?", [$ok, $scrap, $left === 0 ? 'closed' : 'partly_returned', $i['id']]);
            db()->commit();
            notifyIssue('repair_returned', issueRow((int) $i['id']), $u, ['ok' => $ok, 'scrap' => $scrap]);
            if ($dmg && $dmg['status'] === 'pending') {
                notifyIssue('damage_reported', $dmg, $u);
            }
            out(['ok' => true]);
        }

        /* ===== warranties ===== */
        case 'warranty.save': {
            $u = requireUser();
            $b = body();
            $id = idOrNull($b['id'] ?? null);
            $hid = idOrNull($b['hostelId'] ?? null);
            $mine = scopeIds($u);
            if ($mine !== null && !in_array($hid, $mine, true)) {
                if (count($mine) !== 1) {
                    fail('Choose the unit or warehouse.');
                }
                $hid = $mine[0];
            }
            if ($id) {
                $w = q("SELECT * FROM ha_warranties WHERE id=?", [$id])->fetch();
                if (!$w || ($mine !== null && !in_array((int) $w['hostel_id'], $mine, true))) {
                    fail('That warranty no longer exists.');
                }
            }
            $expiry = dateOr($b['expiryAt'] ?? '', null);
            if (!$expiry) {
                fail('Enter the warranty expiry date.');
            }
            $title = str($b['title'] ?? '', 160);
            $iid = idOrNull($b['itemId'] ?? null);
            if (!$iid && !$id) {
                fail('Choose an item.');
            }
            if ($id && !$iid && $title === '') {
                $title = (string) ($w['title'] ?? '');
                if ($title === '') {
                    fail('Choose an item.');
                }
            }
            $qty = ($b['qty'] ?? '') === '' || $b['qty'] === null ? null : max(0, int0($b['qty']));
            $vals = [$hid, idOrNull($b['categoryId'] ?? null), $iid, $title, str($b['warrantyNo'] ?? '', 80), str($b['supplier'] ?? '', 160), $qty,
                dateOr($b['startAt'] ?? '', null), $expiry, str($b['notes'] ?? '', 500)];
            $id ? q("UPDATE ha_warranties SET hostel_id=?, category_id=?, item_id=?, title=?, warranty_no=?, supplier=?, qty=?, start_at=?, expiry_at=?, notes=? WHERE id=?", [...$vals, $id])
                : q("INSERT INTO ha_warranties (hostel_id, category_id, item_id, title, warranty_no, supplier, qty, start_at, expiry_at, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [...$vals, $u['id'], nowMs()]);
            out(['ok' => true]);
        }

        case 'warranty.delete': {
            // Only an Admin can delete entries.
            requireAdmin();
            q("DELETE FROM ha_warranties WHERE id=?", [idOrNull(body()['id'] ?? null) ?? 0]);
            out(['ok' => true]);
        }

        /* ===== notification settings (Admin) ===== */
        case 'settings.save': {
            requireAdmin();
            $b = body();
            $time = (string) ($b['dailyTime'] ?? '09:00');
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                fail('Enter the daily message time as HH:MM, e.g. 09:00.');
            }
            $off = array_values(array_intersect(array_keys(TG_EVENTS), is_array($b['tgOff'] ?? null) ? $b['tgOff'] : []));
            $set = [
                'org_name' => str($b['orgName'] ?? '', 120),
                'tg_off' => implode(',', $off), 'daily_time' => $time,
                'telegram_on' => empty($b['telegramOn']) ? '0' : '1', 'telegram_chat' => str($b['telegramChat'] ?? '', 60),
                'app_url' => str($b['appUrl'] ?? '', 200),
            ];
            if (isset($b['telegramToken']) && trim((string) $b['telegramToken']) !== '') {
                $set['telegram_token'] = str($b['telegramToken'], 120);
            }
            if (!empty($b['clearToken'])) {
                $set['telegram_token'] = '';
            }
            $st = db()->prepare("REPLACE INTO ha_settings (k, v) VALUES (?, ?)");
            foreach ($set as $k => $v) {
                $st->execute([$k, $v]);
            }
            out(['ok' => true]);
        }

        case 'settings.test': {
            requireAdmin();
            out(['result' => sendTest()]);
        }

        case 'daily.run': {
            // Admin sends today's daily messages now (for checking how they look).
            requireAdmin();
            out(runDaily(true));
        }

        case 'cron': {
            // Called once a day (or more often) by a Hostinger cron job; sends the daily messages once.
            $key = (string) ($_GET['key'] ?? '');
            if (!defined('CRON_CLI') && ($key === '' || !hash_equals(cronKey(), $key))) {
                fail('Not allowed.', 403, 'forbidden');
            }
            out(runDaily());
        }

        /* ===== sample data, used by the deploy workflow or an Admin ===== */
        case 'seed': {
            $key = (string) ($_GET['key'] ?? '');
            $okKey = $key !== '' && hash_equals(hash_hmac('sha256', 'hostel-assets-seed', DB_PASS), $key);
            $u = me();
            if (!$okKey && (!$u || $u['role'] !== 'Admin')) {
                fail('Only an Admin can load sample data.', 403, 'forbidden');
            }
            require __DIR__ . '/seed.php';
            out(['added' => seedSample(db())]);
        }

        case 'clear': {
            // Deletes all stock, issues and warranties (masters and users are kept). Deploy workflow only.
            $key = (string) ($_GET['key'] ?? '');
            if ($key === '' || !hash_equals(hash_hmac('sha256', 'hostel-assets-clear', DB_PASS), $key)) {
                fail('Not allowed.', 403, 'forbidden');
            }
            $done = [];
            foreach (['ha_txns', 'ha_issues', 'ha_warranties'] as $t) {
                $done[$t] = db()->exec("DELETE FROM $t");
            }
            out(['deleted' => $done]);
        }

        default:
            fail('Unknown action.', 404);
    }
} catch (PDOException $e) {
    try {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
    } catch (PDOException $ignored) {
        // Could not even connect; handled below.
    }
    if ((int) ($e->errorInfo[1] ?? 0) === 1045 || str_contains($e->getMessage(), '[1045]')) {
        error_log('[hostel-assets] ' . $e->getMessage());
        fail('The app could not log in to the database. Check the DB_USER and DB_PASS secrets in GitHub, then re-run the deploy.', 500, 'unavailable');
    }
    if ((int) ($e->errorInfo[1] ?? 0) === 1049 || str_contains($e->getMessage(), '[1049]')) {
        fail('The database named in DB_NAME does not exist. Check the DB_NAME secret in GitHub, then re-run the deploy.', 500, 'unavailable');
    }
    if ($e->getCode() === '23000') {
        fail('That name or email is already used by another record.');
    }
    error_log('[hostel-assets] ' . $e->getMessage());
    fail('Database error. Check the DB_* settings and try again.', 500, 'unavailable');
}
