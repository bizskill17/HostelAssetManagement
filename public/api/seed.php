<?php
/**
 * Sample data: 4 hostels, 10 items, opening stock, receipts, transfers (approved and pending),
 * repairs (open, part-returned, closed), damage and warranties. Every row is marked sample = 1.
 * Runs only when no sample hostel exists yet, so it is safe to repeat.
 * Sample wardens are created without passwords, so they cannot sign in until an Admin sets one.
 */
declare(strict_types=1);

function seedSample(PDO $db): array
{
    if ((int) $db->query("SELECT COUNT(*) FROM ha_hostels WHERE sample = 1")->fetchColumn() > 0) {
        return ['skipped' => 'Sample data is already loaded.'];
    }
    $now = (int) round(microtime(true) * 1000);
    $d = fn(int $daysAgo) => date('Y-m-d', strtotime(sprintf('%+d days', -$daysAgo)));
    $ins = function (string $sql, array $a) use ($db): int {
        $db->prepare($sql)->execute($a);
        return (int) $db->lastInsertId();
    };
    $cat = [];
    foreach ($db->query("SELECT id, name FROM ha_categories")->fetchAll() as $r) {
        $cat[$r['name']] = (int) $r['id'];
    }

    $H = [];
    foreach ([['Narmada Boys Hostel', 'NBH', 'North campus'], ['Kaveri Boys Hostel', 'KBH', 'North campus'], ['Ganga Girls Hostel', 'GGH', 'South campus'], ['Yamuna Girls Hostel', 'YGH', 'South campus']] as [$n, $c, $a]) {
        $H[$c] = $ins("INSERT INTO ha_hostels (name, code, address, active, sample) VALUES (?, ?, ?, 1, 1)", [$n, $c, $a]);
    }
    $I = [];
    foreach ([
        ['Single bed, steel', 'Furniture', 'Nos'], ['Mattress 4in foam', 'Bedding', 'Nos'], ['Pillow', 'Bedding', 'Nos'],
        ['Study table', 'Furniture', 'Nos'], ['Plastic chair', 'Furniture', 'Nos'], ['Ceiling fan 48in', 'Electrical', 'Nos'],
        ['Water geyser 25L', 'Electrical', 'Nos'], ['RO water purifier', 'Kitchen & Mess', 'Nos'], ['LED tube light', 'Electrical', 'Nos'],
        ['Bedsheet set', 'Bedding', 'Sets'],
    ] as [$n, $c, $u]) {
        $I[$n] = $ins("INSERT INTO ha_items (name, category_id, unit, active, sample) VALUES (?, ?, ?, 1, 1)", [$n, $cat[$c] ?? null, $u]);
    }
    foreach ([['Suresh Kumar', 'suresh.warden@example.com', 'NBH'], ['Ravi Patel', 'ravi.warden@example.com', 'KBH'], ['Meena Sharma', 'meena.warden@example.com', 'GGH'], ['Kavita Rao', 'kavita.warden@example.com', 'YGH']] as [$n, $e, $h]) {
        $ins("INSERT IGNORE INTO ha_users (name, email, role, hostel_id, active, sample) VALUES (?, ?, 'Warden', ?, 1, 1)", [$n, $e, $H[$h]]);
    }

    $tx = function (string $h, string $item, string $kind, int $dir, int $qty, string $at, array $more = []) use ($ins, $H, $I, $now): void {
        $ins("INSERT INTO ha_txns (hostel_id, item_id, kind, dir, qty, issue_id, other_hostel_id, party, at, notes, created_at, sample) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
            [$H[$h], $I[$item], $kind, $dir, $qty, $more['issue'] ?? null, isset($more['other']) ? $H[$more['other']] : null, $more['party'] ?? '', $at, $more['notes'] ?? '', $now]);
    };
    $issue = function (string $kind, string $status, string $h, string $item, int $qty, string $at, array $more = []) use ($ins, $H, $I, $now): int {
        return $ins("INSERT INTO ha_issues (kind, status, hostel_id, to_hostel_id, item_id, qty, place, at, expected_at, notes, returned_qty, scrapped_qty, created_at, decided_at, decision_note, sample)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
            [$kind, $status, $H[$h], isset($more['to']) ? $H[$more['to']] : null, $I[$item], $qty, $more['place'] ?? '', $at, $more['expected'] ?? null,
                $more['notes'] ?? '', $more['returned'] ?? 0, $more['scrapped'] ?? 0, $now, $status === 'pending' ? null : $now, $more['decision'] ?? '']);
    };

    // Opening stock, 60 days ago.
    $open = [
        'NBH' => ['Single bed, steel' => 120, 'Mattress 4in foam' => 120, 'Pillow' => 110, 'Study table' => 60, 'Plastic chair' => 80, 'Ceiling fan 48in' => 60, 'Water geyser 25L' => 8, 'RO water purifier' => 3, 'LED tube light' => 90, 'Bedsheet set' => 200],
        'KBH' => ['Single bed, steel' => 90, 'Mattress 4in foam' => 85, 'Pillow' => 90, 'Study table' => 45, 'Plastic chair' => 50, 'Ceiling fan 48in' => 45, 'Water geyser 25L' => 6, 'RO water purifier' => 2, 'LED tube light' => 70, 'Bedsheet set' => 150],
        'GGH' => ['Single bed, steel' => 100, 'Mattress 4in foam' => 100, 'Pillow' => 100, 'Study table' => 50, 'Plastic chair' => 60, 'Ceiling fan 48in' => 50, 'Water geyser 25L' => 10, 'RO water purifier' => 3, 'LED tube light' => 80, 'Bedsheet set' => 180],
        'YGH' => ['Single bed, steel' => 70, 'Mattress 4in foam' => 70, 'Pillow' => 75, 'Study table' => 35, 'Plastic chair' => 40, 'Ceiling fan 48in' => 35, 'Water geyser 25L' => 5, 'RO water purifier' => 2, 'LED tube light' => 55, 'Bedsheet set' => 120],
    ];
    foreach ($open as $h => $items) {
        foreach ($items as $item => $q) {
            $tx($h, $item, 'opening', 1, $q, $d(60), ['notes' => 'Opening stock']);
        }
    }
    // Received.
    $tx('NBH', 'Single bed, steel', 'in', 1, 20, $d(40), ['party' => 'Comfort Furnishers', 'notes' => 'Bill CF/2211']);
    $tx('NBH', 'Mattress 4in foam', 'in', 1, 20, $d(40), ['party' => 'Comfort Furnishers', 'notes' => 'Bill CF/2211']);
    $tx('GGH', 'Plastic chair', 'in', 1, 30, $d(25), ['party' => 'Supreme Store']);
    $tx('YGH', 'Bedsheet set', 'in', 1, 60, $d(12), ['party' => 'Linen House']);

    // Approved transfers.
    foreach ([['NBH', 'KBH', 'Single bed, steel', 15, 35], ['NBH', 'KBH', 'Mattress 4in foam', 15, 35], ['GGH', 'YGH', 'Plastic chair', 12, 20]] as [$f, $t, $item, $q, $ago]) {
        $id = $issue('transfer', 'approved', $f, $item, $q, $d($ago), ['to' => $t, 'notes' => 'Extra rooms opened']);
        $tx($f, $item, 'transfer_out', -1, $q, $d($ago - 1), ['issue' => $id, 'other' => $t]);
        $tx($t, $item, 'transfer_in', 1, $q, $d($ago - 1), ['issue' => $id, 'other' => $f]);
    }
    // Pending transfers.
    $issue('transfer', 'pending', 'NBH', 'Study table', 8, $d(1), ['to' => 'YGH', 'notes' => 'For new block wing']);
    $issue('transfer', 'pending', 'KBH', 'Ceiling fan 48in', 4, $d(0), ['to' => 'GGH']);
    // Rejected transfer.
    $issue('transfer', 'rejected', 'YGH', 'Pillow', 10, $d(15), ['to' => 'KBH', 'decision' => 'Not needed, we have enough']);

    // Repairs.
    $id = $issue('repair', 'at_repair', 'NBH', 'Ceiling fan 48in', 5, $d(9), ['place' => 'Sharma Electricals', 'expected' => $d(2), 'notes' => 'Regulator and capacitor']);
    $tx('NBH', 'Ceiling fan 48in', 'repair_out', -1, 5, $d(9), ['issue' => $id, 'party' => 'Sharma Electricals']);
    $id = $issue('repair', 'at_repair', 'GGH', 'RO water purifier', 1, $d(4), ['place' => 'AquaPure Services', 'expected' => $d(-3), 'notes' => 'Membrane change']);
    $tx('GGH', 'RO water purifier', 'repair_out', -1, 1, $d(4), ['issue' => $id, 'party' => 'AquaPure Services']);
    $id = $issue('repair', 'partly_returned', 'KBH', 'Water geyser 25L', 3, $d(18), ['place' => 'Sharma Electricals', 'expected' => $d(8), 'returned' => 1]);
    $tx('KBH', 'Water geyser 25L', 'repair_out', -1, 3, $d(18), ['issue' => $id, 'party' => 'Sharma Electricals']);
    $tx('KBH', 'Water geyser 25L', 'repair_return', 1, 1, $d(10), ['issue' => $id, 'party' => 'Sharma Electricals']);
    $id = $issue('repair', 'closed', 'YGH', 'Study table', 4, $d(30), ['place' => 'Local carpenter', 'returned' => 3, 'scrapped' => 1]);
    $tx('YGH', 'Study table', 'repair_out', -1, 4, $d(30), ['issue' => $id, 'party' => 'Local carpenter']);
    $tx('YGH', 'Study table', 'repair_return', 1, 3, $d(22), ['issue' => $id, 'party' => 'Local carpenter']);
    $tx('YGH', 'Study table', 'repair_return', 1, 1, $d(22), ['issue' => $id, 'party' => 'Local carpenter', 'notes' => 'Not repairable']);
    $tx('YGH', 'Study table', 'damaged', -1, 1, $d(22), ['issue' => $id, 'party' => 'Scrap dealer', 'notes' => 'Not repairable after repair RP-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT)]);

    // Damage.
    foreach ([['NBH', 'Plastic chair', 6, 14, 'Scrap dealer', 'Broken legs'], ['KBH', 'LED tube light', 9, 7, 'Disposed', 'Fused'], ['GGH', 'Pillow', 5, 3, '', 'Torn']] as [$h, $item, $q, $ago, $place, $note]) {
        $id = $issue('damage', 'done', $h, $item, $q, $d($ago), ['place' => $place, 'notes' => $note]);
        $tx($h, $item, 'damaged', -1, $q, $d($ago), ['issue' => $id, 'party' => $place, 'notes' => $note]);
    }

    // Warranties.
    foreach ([
        ['NBH', 'Electrical', 'Water geyser 25L', 'Geysers — Racold', 'RC-88213', 'Racold dealer', 8, 300, 20],
        ['GGH', 'Kitchen & Mess', 'RO water purifier', 'RO purifiers — AquaPure AMC', 'AMC-2291', 'AquaPure Services', 3, 340, 25],
        ['KBH', 'Electrical', 'Ceiling fan 48in', 'Ceiling fans — Crompton', 'CR-55102', 'Sharma Electricals', 45, 700, 70],
        ['YGH', 'Furniture', 'Single bed, steel', 'Steel beds', 'CF-W-1180', 'Comfort Furnishers', 70, 365, 180],
        ['NBH', 'Furniture', 'Study table', 'Study tables', 'CF-W-0912', 'Comfort Furnishers', 60, 400, -10],
    ] as [$h, $c, $item, $title, $no, $sup, $q, $startAgo, $expIn]) {
        $ins("INSERT INTO ha_warranties (hostel_id, category_id, item_id, title, warranty_no, supplier, qty, start_at, expiry_at, created_at, sample) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
            [$H[$h], $cat[$c] ?? null, $I[$item], $title, $no, $sup, $q, $d($startAgo), date('Y-m-d', strtotime(($expIn >= 0 ? '+' : '') . "$expIn days")), $now]);
    }
    return ['hostels' => 4, 'items' => 10];
}
