<?php
/**
 * Creates the tables on first run and loads default masters. Safe to run repeatedly.
 * All tables use the ha_ prefix so they never clash with the older tracker's tables.
 */
declare(strict_types=1);

const SCHEMA_VERSION = 4;

function ensureSchema(PDO $db): void
{
    try {
        $v = (int) $db->query("SELECT v FROM ha_meta WHERE k = 'schema'")->fetchColumn();
        if ($v >= SCHEMA_VERSION) {
            return;
        }
    } catch (PDOException $e) {
        // fresh database
    }

    $E = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $tables = [
        "CREATE TABLE IF NOT EXISTS ha_meta (k VARCHAR(40) PRIMARY KEY, v TEXT NOT NULL) $E",
        "CREATE TABLE IF NOT EXISTS ha_hostels (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL, code VARCHAR(10) NOT NULL DEFAULT '',
            address VARCHAR(255) NOT NULL DEFAULT '', phone VARCHAR(40) NOT NULL DEFAULT '',
            active TINYINT(1) NOT NULL DEFAULT 1, sample TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY uq_hostel_name (name)
        ) $E",
        "CREATE TABLE IF NOT EXISTS ha_categories (
            id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL,
            UNIQUE KEY uq_cat_name (name)
        ) $E",
        "CREATE TABLE IF NOT EXISTS ha_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL, category_id INT NULL, unit VARCHAR(20) NOT NULL DEFAULT 'Nos',
            notes VARCHAR(255) NOT NULL DEFAULT '', active TINYINT(1) NOT NULL DEFAULT 1,
            sample TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY uq_item_name (name)
        ) $E",
        "CREATE TABLE IF NOT EXISTS ha_units (
            id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(20) NOT NULL,
            UNIQUE KEY uq_unit_name (name)
        ) $E",
        "CREATE TABLE IF NOT EXISTS ha_damage_places (
            id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL,
            UNIQUE KEY uq_dp_name (name)
        ) $E",
        "CREATE TABLE IF NOT EXISTS ha_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL,
            role VARCHAR(10) NOT NULL DEFAULT 'Warden', hostel_id INT NULL,
            phone VARCHAR(40) NOT NULL DEFAULT '', active TINYINT(1) NOT NULL DEFAULT 1,
            password_hash VARCHAR(255) NULL, sample TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY uq_user_email (email)
        ) $E",
        // Every stock change. dir is +1 (in) or -1 (out); qty is always positive.
        "CREATE TABLE IF NOT EXISTS ha_txns (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            hostel_id INT NOT NULL, item_id INT NOT NULL,
            kind VARCHAR(20) NOT NULL, dir TINYINT NOT NULL, qty INT NOT NULL,
            issue_id INT NULL, other_hostel_id INT NULL,
            party VARCHAR(160) NOT NULL DEFAULT '', at DATE NOT NULL, notes VARCHAR(500) NOT NULL DEFAULT '',
            created_by INT NULL, created_at BIGINT NOT NULL, sample TINYINT(1) NOT NULL DEFAULT 0,
            KEY idx_tx_hi (hostel_id, item_id), KEY idx_tx_issue (issue_id)
        ) $E",
        // Issue documents: transfer (needs approval), repair (tracked until returned), damage.
        "CREATE TABLE IF NOT EXISTS ha_issues (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(10) NOT NULL, status VARCHAR(20) NOT NULL,
            hostel_id INT NOT NULL, to_hostel_id INT NULL, item_id INT NOT NULL, qty INT NOT NULL,
            place VARCHAR(160) NOT NULL DEFAULT '', at DATE NOT NULL, expected_at DATE NULL,
            notes VARCHAR(500) NOT NULL DEFAULT '',
            returned_qty INT NOT NULL DEFAULT 0, scrapped_qty INT NOT NULL DEFAULT 0, received_qty INT NULL,
            created_by INT NULL, created_at BIGINT NOT NULL,
            decided_by INT NULL, decided_at BIGINT NULL, decision_note VARCHAR(500) NOT NULL DEFAULT '',
            sample TINYINT(1) NOT NULL DEFAULT 0,
            KEY idx_is_from (hostel_id), KEY idx_is_to (to_hostel_id), KEY idx_is_status (status)
        ) $E",
        "CREATE TABLE IF NOT EXISTS ha_warranties (
            id INT AUTO_INCREMENT PRIMARY KEY,
            hostel_id INT NULL, category_id INT NULL, item_id INT NULL,
            title VARCHAR(160) NOT NULL DEFAULT '', warranty_no VARCHAR(80) NOT NULL DEFAULT '',
            supplier VARCHAR(160) NOT NULL DEFAULT '', qty INT NULL,
            start_at DATE NULL, expiry_at DATE NOT NULL, notes VARCHAR(500) NOT NULL DEFAULT '',
            created_by INT NULL, created_at BIGINT NOT NULL, sample TINYINT(1) NOT NULL DEFAULT 0,
            KEY idx_w_exp (expiry_at)
        ) $E",
        "CREATE TABLE IF NOT EXISTS ha_settings (k VARCHAR(40) PRIMARY KEY, v TEXT NOT NULL) $E",
    ];
    foreach ($tables as $sql) {
        $db->exec($sql);
    }

    // v2: transfers record how many actually arrived.
    if (!$db->query("SHOW COLUMNS FROM ha_issues LIKE 'received_qty'")->fetch()) {
        $db->exec("ALTER TABLE ha_issues ADD COLUMN received_qty INT NULL AFTER scrapped_qty");
        $db->exec("UPDATE ha_issues SET received_qty = qty, status = 'received' WHERE kind = 'transfer' AND status = 'approved'");
        $db->exec("UPDATE ha_issues SET received_qty = 0 WHERE kind = 'transfer' AND status = 'rejected'");
    }

    $defaults = [
        'ha_categories' => ['Furniture', 'Bedding', 'Electrical', 'Electronics', 'Kitchen & Mess', 'Sports', 'Cleaning', 'Other'],
        'ha_damage_places' => ['Scrap dealer', 'Returned to supplier', 'Disposed', 'Kept in store'],
        'ha_units' => ['Nos', 'Pcs', 'Sets', 'Pairs', 'Kg', 'Litres', 'Metres', 'Boxes'],
    ];
    foreach ($defaults as $t => $names) {
        if ((int) $db->query("SELECT COUNT(*) FROM $t")->fetchColumn() === 0) {
            $st = $db->prepare("INSERT INTO $t (name) VALUES (?)");
            foreach ($names as $n) {
                $st->execute([$n]);
            }
        }
    }
    // v3: every unit already used by an item is in the units list.
    $db->exec("INSERT IGNORE INTO ha_units (name) SELECT DISTINCT unit FROM ha_items WHERE unit <> ''");

    // v4: damage carries the approved quantity; older entries were approved in full.
    $db->exec("UPDATE ha_issues SET received_qty = qty WHERE kind = 'damage' AND status = 'done' AND received_qty IS NULL");

    $db->prepare("REPLACE INTO ha_meta (k, v) VALUES ('schema', ?)")->execute([(string) SCHEMA_VERSION]);
}
