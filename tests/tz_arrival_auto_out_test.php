<?php
/**
 * ТЗ FINAL: авто-списание после прихода + идемпотентность (без двойного OUT).
 * Запуск: php tests/tz_arrival_auto_out_test.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/order_supply.php';
require_once dirname(__DIR__) . '/api/lib/inventory_arrival_auto_out.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE orders (order_id TEXT, document_id TEXT, order_lines_json TEXT, date_updated TEXT)');
$pdo->exec(
    'CREATE TABLE order_warehouse_lines (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id TEXT,
        name TEXT,
        qty REAL,
        purchase_price REAL,
        sale_price REAL,
        status TEXT,
        expected_date TEXT,
        inventory_item_id INTEGER,
        from_stock INTEGER NOT NULL DEFAULT 0,
        order_line_key TEXT,
        qty_received REAL NOT NULL DEFAULT 0,
        created_at TEXT,
        updated_at TEXT
    )'
);
$pdo->exec(
    'CREATE TABLE inventory_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        default_cost REAL DEFAULT 0,
        sale_price REAL DEFAULT 0,
        sku TEXT,
        updated_at TEXT
    )'
);
$pdo->exec(
    'CREATE TABLE inventory_balances (
        item_id INTEGER PRIMARY KEY,
        quantity REAL NOT NULL DEFAULT 0,
        updated_at TEXT
    )'
);
$pdo->exec(
    'CREATE TABLE inventory_movements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        item_id INTEGER NOT NULL,
        movement_type TEXT NOT NULL,
        quantity_delta REAL NOT NULL,
        unit_cost REAL,
        ref_kind TEXT,
        ref_id TEXT,
        note TEXT,
        created_at TEXT NOT NULL,
        created_by TEXT
    )'
);
$pdo->exec(
    'CREATE TRIGGER trg_inventory_movement_after_insert
    AFTER INSERT ON inventory_movements
    FOR EACH ROW
    BEGIN
        INSERT INTO inventory_balances (item_id, quantity, updated_at)
        VALUES (NEW.item_id, NEW.quantity_delta, NEW.created_at)
        ON CONFLICT(item_id) DO UPDATE SET
            quantity = inventory_balances.quantity + NEW.quantity_delta,
            updated_at = NEW.created_at;
    END'
);

$oid = 'ORD-TZ-ARR';
$lineKey = 'OL-testline00000001';
$linesJson = json_encode(
    [['name' => 'Test part', 'line_id' => $lineKey, 'qty' => 2, 'purchase' => 10, 'sale' => 20]],
    JSON_UNESCAPED_UNICODE
);
$pdo->prepare('INSERT INTO orders (order_id, document_id, order_lines_json, date_updated) VALUES (?,?,?,?)')
    ->execute([$oid, $oid, $linesJson, date('c')]);

$pdo->prepare('INSERT INTO inventory_items (id, default_cost, updated_at) VALUES (1, 5, ?)')->execute([date('c')]);
$pdo->prepare('INSERT INTO inventory_balances (item_id, quantity, updated_at) VALUES (1, 0, ?)')->execute([date('c')]);

$pdo->prepare(
    'INSERT INTO order_warehouse_lines (order_id, name, qty, purchase_price, sale_price, status, inventory_item_id, from_stock, order_line_key, qty_received, created_at, updated_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
)->execute([$oid, 'Test part', 2.0, 10.0, 20.0, 'ordered', 1, 0, $lineKey, 0, date('c'), date('c')]);
$owlId = (int) $pdo->lastInsertId();

$now = date('c');

// IN +2
$pdo->prepare(
    'INSERT INTO inventory_movements (item_id, movement_type, quantity_delta, unit_cost, ref_kind, ref_id, note, created_at, created_by)
     VALUES (?,?,?,?,?,?,?,?,?)'
)->execute([1, 'in', 2.0, 10.0, 'order', $oid, 'test in', $now, null]);
$inMid = (int) $pdo->lastInsertId();

$r = fixarivan_apply_auto_order_line_out_after_inward($pdo, $inMid, 1, 2.0, $owlId, $now);
if (($r['out_qty'] ?? 0) < 2.0 - 1e-6) {
    fwrite(STDERR, "FAIL: expected out_qty 2, got " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}

$bal = (float) ($pdo->query('SELECT quantity FROM inventory_balances WHERE item_id = 1')->fetchColumn() ?: 0);
if (abs($bal) > 1e-6) {
    fwrite(STDERR, "FAIL: balance after IN+OUT should be ~0, got {$bal}\n");
    exit(1);
}

$sum = fixarivan_sum_order_line_out_quantity($pdo, 1, $oid, $lineKey);
if ($sum < 2.0 - 1e-6) {
    fwrite(STDERR, "FAIL: sum out expected 2, got {$sum}\n");
    exit(1);
}

// Идемпотентность: повтор тот же inMovementId
$r2 = fixarivan_apply_auto_order_line_out_after_inward($pdo, $inMid, 1, 2.0, $owlId, $now);
if (($r2['out_qty'] ?? -1) > 1e-6 || ($r2['skipped'] ?? '') !== 'already_arr_out') {
    fwrite(STDERR, "FAIL: second call should skip, got " . json_encode($r2) . "\n");
    exit(1);
}

// Имитация Track: полный ORDER_LINE_OUT — не должен добавлять третий OUT при новом приходе
$pdo->prepare(
    'INSERT INTO inventory_movements (item_id, movement_type, quantity_delta, unit_cost, ref_kind, ref_id, note, created_at, created_by)
     VALUES (?,?,?,?,?,?,?,?,?)'
)->execute([1, 'in', 5.0, 10.0, 'order', $oid, 'in2', $now, null]);
$inMid2 = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO inventory_movements (item_id, movement_type, quantity_delta, unit_cost, ref_kind, ref_id, note, created_at, created_by)
     VALUES (?,?,?,?,?,?,?,?,?)'
)->execute([1, 'out', -2.0, 5.0, 'order', $oid, 'ORDER_LINE_OUT:' . $oid . ':' . $lineKey, $now, null]);

$r3 = fixarivan_apply_auto_order_line_out_after_inward($pdo, $inMid2, 1, 5.0, $owlId, $now);
if (($r3['out_qty'] ?? -1) > 1e-6 || ($r3['skipped'] ?? '') !== 'line_already_out') {
    fwrite(STDERR, "FAIL: after Track OUT should not auto-out again, got " . json_encode($r3) . "\n");
    exit(1);
}

echo "OK tz_arrival_auto_out_test\n";
exit(0);
