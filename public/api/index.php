<?php
/**
 * Hostel Asset Management — JSON API (PHP 8 + MySQL/MariaDB on Hostinger).
 *
 * One organisation, many hostels. Stock is counted by quantity per hostel and item;
 * every change is a row in ha_txns, so any item's ledger can be rebuilt from opening onwards.
 *
 * Roles: Admin (all hostels, masters, settings) and Warden (own hostel only).
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
session_start();

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

/* ---------- users & access ---------- */
function me(): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $u = q("SELECT id, name, email, role, hostel_id, active FROM ha_users WHERE id = ? AND password_hash IS NOT NULL", [$_SESSION['uid']])->fetch();
    if (!$u || !(int) $u['active']) {
        unset($_SESSION['uid']);
        return null;
    }
    $u['id'] = (int) $u['id'];
    $u['hostel_id'] = $u['hostel_id'] === null ? null : (int) $u['hostel_id'];
    return $u;
}
function requireUser(): array
{
    $u = me();
    if (!$u) {
        fail('Please sign in again.', 401, 'unauthenticated');
    }
    if ($u['role'] !== 'Admin' && !$u['hostel_id']) {
        fail('Your login is not linked to a hostel yet. Ask an Admin to set your hostel.', 403, 'forbidden');
    }
    return $u;
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
    return $hid !== null && (isAdmin($u) || $u['hostel_id'] === $hid);
}
function publicMe(?array $u): ?array
{
    return $u ? ['id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role'], 'hostelId' => $u['hostel_id']] : null;
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
    return (int) q("SELECT COALESCE(SUM(qty), 0) FROM ha_issues WHERE kind = 'transfer' AND status = 'pending' AND hostel_id = ? AND item_id = ? AND id <> ?", [$hid, $iid, $exceptIssue])->fetchColumn();
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
function shapeIssue(array $r): array
{
    return [
        'id' => (int) $r['id'], 'no' => issueNo($r), 'kind' => $r['kind'], 'status' => $r['status'],
        'hostelId' => (int) $r['hostel_id'], 'toHostelId' => $r['to_hostel_id'] === null ? null : (int) $r['to_hostel_id'],
        'itemId' => (int) $r['item_id'], 'qty' => (int) $r['qty'], 'place' => $r['place'], 'at' => $r['at'],
        'expectedAt' => $r['expected_at'], 'notes' => $r['notes'], 'returnedQty' => (int) $r['returned_qty'],
        'scrappedQty' => (int) $r['scrapped_qty'], 'receivedQty' => $r['received_qty'] === null ? null : (int) $r['received_qty'], 'createdBy' => $r['created_by'] === null ? null : (int) $r['created_by'],
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
            $h = $u['hostel_id'];
            $res = ['me' => publicMe($u)];
            $res['hostels'] = array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'code' => $r['code'], 'address' => $r['address'], 'phone' => $r['phone'], 'active' => (bool) $r['active']],
                db()->query("SELECT * FROM ha_hostels ORDER BY name")->fetchAll());
            $res['categories'] = array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name']], db()->query("SELECT * FROM ha_categories ORDER BY name")->fetchAll());
            $res['units'] = array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name']], db()->query("SELECT * FROM ha_units ORDER BY name")->fetchAll());
            $res['damagePlaces'] = array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name']], db()->query("SELECT * FROM ha_damage_places ORDER BY name")->fetchAll());
            $res['items'] = array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'categoryId' => $r['category_id'] === null ? null : (int) $r['category_id'], 'unit' => $r['unit'], 'notes' => $r['notes'], 'active' => (bool) $r['active']],
                db()->query("SELECT * FROM ha_items ORDER BY name")->fetchAll());
            $res['users'] = array_map(function ($r) use ($admin) {
                $o = ['id' => (int) $r['id'], 'name' => $r['name'], 'role' => $r['role'], 'hostelId' => $r['hostel_id'] === null ? null : (int) $r['hostel_id']];
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
            ], ($admin ? db()->query("SELECT * FROM ha_txns ORDER BY at, id") : q("SELECT * FROM ha_txns WHERE hostel_id = ? ORDER BY at, id", [$h]))->fetchAll());

            $res['issues'] = array_map('shapeIssue', ($admin
                ? db()->query("SELECT * FROM ha_issues ORDER BY id DESC")
                : q("SELECT * FROM ha_issues WHERE hostel_id = ? OR to_hostel_id = ? ORDER BY id DESC", [$h, $h]))->fetchAll());

            $res['warranties'] = array_map(fn($r) => [
                'id' => (int) $r['id'], 'hostelId' => $r['hostel_id'] === null ? null : (int) $r['hostel_id'],
                'categoryId' => $r['category_id'] === null ? null : (int) $r['category_id'], 'itemId' => $r['item_id'] === null ? null : (int) $r['item_id'],
                'title' => $r['title'], 'warrantyNo' => $r['warranty_no'], 'supplier' => $r['supplier'], 'qty' => $r['qty'] === null ? null : (int) $r['qty'],
                'startAt' => $r['start_at'], 'expiryAt' => $r['expiry_at'], 'notes' => $r['notes'],
            ], ($admin ? db()->query("SELECT * FROM ha_warranties ORDER BY expiry_at") : q("SELECT * FROM ha_warranties WHERE hostel_id = ? ORDER BY expiry_at", [$h]))->fetchAll());

            if ($admin) {
                $s = settings();
                $res['settings'] = [
                    'emailOn' => ($s['email_on'] ?? '0') === '1', 'emailFrom' => $s['email_from'] ?? '', 'emailTo' => $s['email_to'] ?? '',
                    'telegramOn' => ($s['telegram_on'] ?? '0') === '1', 'telegramChat' => $s['telegram_chat'] ?? '',
                    'telegramTokenSet' => ($s['telegram_token'] ?? '') !== '', 'appUrl' => $s['app_url'] ?? '',
                ];
            }
            $res['today'] = today();
            out($res);
        }

        /* ===== masters (Admin only) ===== */
        case 'master.save': {
            requireAdmin();
            $b = body();
            $coll = (string) ($b['coll'] ?? '');
            $id = idOrNull($b['id'] ?? null);
            $d = is_array($b['data'] ?? null) ? $b['data'] : [];
            switch ($coll) {
                case 'hostels':
                    $name = str($d['name'] ?? '', 120);
                    if ($name === '') {
                        fail('Enter the hostel name.');
                    }
                    $vals = [$name, str($d['address'] ?? ''), empty($d['active']) ? 0 : 1];
                    $id ? q("UPDATE ha_hostels SET name=?, address=?, active=? WHERE id=?", [...$vals, $id])
                        : q("INSERT INTO ha_hostels (name, address, active) VALUES (?, ?, ?)", $vals);
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
                    $role = ($d['role'] ?? '') === 'Admin' ? 'Admin' : 'Warden';
                    $hid = $role === 'Warden' ? idOrNull($d['hostelId'] ?? null) : null;
                    $active = empty($d['active']) ? 0 : 1;
                    $pass = (string) ($d['password'] ?? '');
                    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        fail('Enter a name and a valid email.');
                    }
                    if ($role === 'Warden' && !$hid) {
                        fail('Choose the hostel this warden looks after.');
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
                    $vals = [$name, $email, $role, $hid, str($d['phone'] ?? '', 40), $active];
                    if ($id) {
                        q("UPDATE ha_users SET name=?, email=?, role=?, hostel_id=?, phone=?, active=? WHERE id=?", [...$vals, $id]);
                    } else {
                        q("INSERT INTO ha_users (name, email, role, hostel_id, phone, active) VALUES (?, ?, ?, ?, ?, ?)", $vals);
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
                    if ($used("SELECT 1 FROM ha_txns WHERE ? IN (hostel_id, other_hostel_id) LIMIT 1") || $used("SELECT 1 FROM ha_issues WHERE ? IN (hostel_id, to_hostel_id) LIMIT 1")) {
                        fail('This hostel has stock history, so it can\'t be deleted. Mark it inactive instead.');
                    }
                    if ($used("SELECT 1 FROM ha_users WHERE hostel_id=? LIMIT 1")) {
                        fail('Some wardens are linked to this hostel. Move them to another hostel first.');
                    }
                    q("DELETE FROM ha_hostels WHERE id=?", [$id]);
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
            $u = requireUser();
            $b = body();
            $hid = idOrNull($b['hostelId'] ?? null);
            $iid = idOrNull($b['itemId'] ?? null);
            $kind = ($b['kind'] ?? '') === 'opening' ? 'opening' : 'in';
            $qty = int0($b['qty'] ?? 0);
            if (!canHostel($u, $hid) || !hostelRow($hid)) {
                fail('Choose your hostel.');
            }
            if (!itemRow($iid)) {
                fail('Choose an item.');
            }
            if ($qty <= 0 || $qty > 1000000) {
                fail('Enter a quantity greater than 0.');
            }
            if ($kind === 'in' && str($b['notes'] ?? '') === '') {
                fail('Enter notes for items received (where they came from, bill number…).');
            }
            if ($kind === 'opening' && (int) q("SELECT COUNT(*) FROM ha_txns WHERE hostel_id=? AND item_id=? AND kind='opening'", [$hid, $iid])->fetchColumn() > 0) {
                fail('Opening stock for this item is already entered for this hostel. Add new stock as "Received" instead.');
            }
            addTxn(['hostel_id' => $hid, 'item_id' => $iid, 'kind' => $kind, 'dir' => 1, 'qty' => $qty,
                'party' => str($b['party'] ?? '', 160), 'at' => dateOr($b['at'] ?? '', today()), 'notes' => str($b['notes'] ?? '', 500)], $u);
            out(['ok' => true]);
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
                fail('Choose the hostel the items are leaving.');
            }
            $item = itemRow($iid);
            if (!$item) {
                fail('Choose an item.');
            }
            if ($qty <= 0) {
                fail('Enter a quantity greater than 0.');
            }
            if ($kind === 'transfer') {
                $toRow = hostelRow($to);
                if (!$toRow || !(int) $toRow['active']) {
                    fail('Choose the hostel receiving the items.');
                }
                if ($to === $hid) {
                    fail('The receiving hostel must be different.');
                }
            }
            db()->beginTransaction();
            lockHostel($hid);
            $avail = onHand($hid, $iid) - pendingOut($hid, $iid);
            if ($qty > $avail) {
                db()->rollBack();
                fail("Only $avail {$item['unit']} of {$item['name']} available to issue at this hostel.");
            }
            $status = ['transfer' => 'pending', 'repair' => 'at_repair', 'damage' => 'done'][$kind];
            q("INSERT INTO ha_issues (kind, status, hostel_id, to_hostel_id, item_id, qty, place, at, expected_at, notes, created_by, created_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
                $kind, $status, $hid, $kind === 'transfer' ? $to : null, $iid, $qty, $kind === 'transfer' ? '' : $place, $at,
                $kind === 'repair' ? dateOr($b['expectedAt'] ?? '', null) : null, $notes, $u['id'], nowMs(),
            ]);
            $issueId = (int) db()->lastInsertId();
            if ($kind === 'repair') {
                addTxn(['hostel_id' => $hid, 'item_id' => $iid, 'kind' => 'repair_out', 'dir' => -1, 'qty' => $qty, 'issue_id' => $issueId, 'party' => $place, 'at' => $at, 'notes' => $notes], $u);
            } elseif ($kind === 'damage') {
                addTxn(['hostel_id' => $hid, 'item_id' => $iid, 'kind' => 'damaged', 'dir' => -1, 'qty' => $qty, 'issue_id' => $issueId, 'party' => $place, 'at' => $at, 'notes' => $notes], $u);
            }
            db()->commit();
            $issue = issueRow($issueId);
            if ($kind !== 'damage') {
                notifyIssue($kind === 'transfer' ? 'transfer_sent' : 'repair_sent', $issue, $u);
            }
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
                fail('Only the receiving hostel can update this transfer.', 403, 'forbidden');
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
                    fail("The sending hostel now has only $avail in stock, so $recvQty can't be received. Ask them to cancel and resend.");
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

        case 'issue.cancel': {
            // The sending hostel withdraws a transfer the other hostel hasn't updated yet.
            $u = requireUser();
            $i = issueRow(idOrNull(body()['id'] ?? null) ?? 0);
            if ($i['kind'] !== 'transfer' || $i['status'] !== 'pending') {
                fail('Only a transfer waiting for approval can be cancelled.');
            }
            if (!canHostel($u, (int) $i['hostel_id'])) {
                fail('Only the sending hostel can cancel this transfer.', 403, 'forbidden');
            }
            q("UPDATE ha_issues SET status='cancelled', decided_by=?, decided_at=? WHERE id=? AND status='pending'", [$u['id'], nowMs(), $i['id']]);
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
                fail('Only the hostel that sent these items can record their return.', 403, 'forbidden');
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
            if ($scrap > 0) {
                // Comes back on the books from repair, then goes out as damaged, so every column still adds up.
                addTxn($base + ['kind' => 'repair_return', 'dir' => 1, 'qty' => $scrap, 'party' => $i['place'], 'notes' => 'Not repairable' . ($notes ? " — $notes" : '')], $u);
                addTxn($base + ['kind' => 'damaged', 'dir' => -1, 'qty' => $scrap, 'party' => str($b['place'] ?? '', 160), 'notes' => 'Not repairable after repair ' . issueNo($i) . ($notes ? " — $notes" : '')], $u);
            }
            $left = $outstanding - $ok - $scrap;
            q("UPDATE ha_issues SET returned_qty = returned_qty + ?, scrapped_qty = scrapped_qty + ?, status = ? WHERE id = ?", [$ok, $scrap, $left === 0 ? 'closed' : 'partly_returned', $i['id']]);
            db()->commit();
            notifyIssue('repair_returned', issueRow((int) $i['id']), $u, ['ok' => $ok, 'scrap' => $scrap]);
            out(['ok' => true]);
        }

        /* ===== warranties ===== */
        case 'warranty.save': {
            $u = requireUser();
            $b = body();
            $id = idOrNull($b['id'] ?? null);
            $hid = idOrNull($b['hostelId'] ?? null);
            if (!isAdmin($u)) {
                $hid = $u['hostel_id'];
            }
            if ($id) {
                $w = q("SELECT * FROM ha_warranties WHERE id=?", [$id])->fetch();
                if (!$w || (!isAdmin($u) && (int) $w['hostel_id'] !== $u['hostel_id'])) {
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
            $emailTo = implode(', ', array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) ($b['emailTo'] ?? ''))), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
            $from = str($b['emailFrom'] ?? '', 190);
            if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
                fail('Enter a valid "From" email address, or leave it blank.');
            }
            $set = [
                'email_on' => empty($b['emailOn']) ? '0' : '1', 'email_from' => $from, 'email_to' => $emailTo,
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
            $u = requireAdmin();
            out(['result' => sendTest($u)]);
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
