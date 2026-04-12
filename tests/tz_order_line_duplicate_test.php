<?php
/**
 * TZ v2-add H: две одинаковые по имени позиции — разные line_id / owl → разные inventory_item_id.
 * Запуск: php tests/tz_order_line_duplicate_test.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/order_supply.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE orders (order_id TEXT, document_id TEXT, order_lines_json TEXT)');
$pdo->exec('CREATE TABLE order_warehouse_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id TEXT, order_line_key TEXT)');

$oid = 'ORD-DUP-TEST';
$lines = json_encode(
    [
        ['name' => 'Same part', 'line_id' => 'OL-aaaaaaaaaaaaaaaa', 'inventory_item_id' => 101],
        ['name' => 'Same part', 'line_id' => 'OL-bbbbbbbbbbbbbbbb', 'inventory_item_id' => 202],
    ],
    JSON_UNESCAPED_UNICODE
);
$pdo->prepare('INSERT INTO orders (order_id, document_id, order_lines_json) VALUES (?,?,?)')->execute([$oid, $oid, $lines]);

$pdo->prepare('INSERT INTO order_warehouse_lines (order_id, order_line_key) VALUES (?,?)')->execute([$oid, 'OL-aaaaaaaaaaaaaaaa']);
$owl1 = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO order_warehouse_lines (order_id, order_line_key) VALUES (?,?)')->execute([$oid, 'OL-bbbbbbbbbbbbbbbb']);
$owl2 = (int) $pdo->lastInsertId();

$a = fixarivan_resolve_inventory_item_id_for_order_line($pdo, $oid, 'Same part', null, $owl1);
$b = fixarivan_resolve_inventory_item_id_for_order_line($pdo, $oid, 'Same part', null, $owl2);
if ($a !== 101 || $b !== 202) {
    fwrite(STDERR, "FAIL owl resolve: expected 101,202 got {$a},{$b}\n");
    exit(1);
}

$noOwl = fixarivan_resolve_inventory_item_id_for_order_line($pdo, $oid, 'Same part', null, null);
if ($noOwl !== 0) {
    fwrite(STDERR, "FAIL without owl_id: expected 0 got {$noOwl}\n");
    exit(1);
}

echo "OK tz_order_line_duplicate_test\n";
exit(0);
