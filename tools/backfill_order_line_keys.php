<?php
/**
 * CLI: заполнить line_id в order_lines_json и order_line_key в order_warehouse_lines
 * (миграция после TZ v2-add без ручного сохранения каждого заказа).
 *
 * Usage:
 *   php tools/backfill_order_line_keys.php
 *   php tools/backfill_order_line_keys.php --dry-run
 */
declare(strict_types=1);

$dryRun = in_array('--dry-run', $argv, true);

$root = dirname(__DIR__);
require_once $root . '/api/sqlite.php';
require_once $root . '/api/lib/order_warehouse_sync.php';

/**
 * @return array{json_updated: bool, owl_updated: int}
 */
function fixarivan_backfill_one_order(PDO $pdo, string $orderKey, bool $dryRun): array {
    $orderKey = trim($orderKey);
    $out = ['json_updated' => false, 'owl_updated' => 0];
    if ($orderKey === '') {
        return $out;
    }

    $st = $pdo->prepare('SELECT order_lines_json FROM orders WHERE order_id = :o OR document_id = :d LIMIT 1');
    $st->execute([':o' => $orderKey, ':d' => $orderKey]);
    $raw = $st->fetchColumn();
    if (!is_string($raw) || trim($raw) === '' || trim($raw) === '[]') {
        return $out;
    }

    $newJson = fixarivan_ensure_order_line_ids($raw);
    if ($newJson !== $raw) {
        $out['json_updated'] = true;
        if (!$dryRun) {
            $pdo->prepare('UPDATE orders SET order_lines_json = :j, date_updated = :u WHERE order_id = :o OR document_id = :d')
                ->execute([':j' => $newJson, ':u' => date('c'), ':o' => $orderKey, ':d' => $orderKey]);
        }
    }

    $lines = json_decode($newJson, true);
    if (!is_array($lines) || $lines === []) {
        return $out;
    }

    $owlSt = $pdo->prepare(
        'SELECT id, inventory_item_id, order_line_key, name FROM order_warehouse_lines WHERE order_id = :oid ORDER BY id ASC'
    );
    $owlSt->execute([':oid' => $orderKey]);
    $owlRows = $owlSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $needsKey = static function (array $r): bool {
        $k = trim((string)($r['order_line_key'] ?? ''));

        return $k === '';
    };

    $byIndex = [];
    if (count($owlRows) === count($lines)) {
        foreach ($owlRows as $i => $owl) {
            if (!$needsKey($owl)) {
                continue;
            }
            $kid = trim((string)($lines[$i]['line_id'] ?? $lines[$i]['orderLineId'] ?? $lines[$i]['lineId'] ?? ''));
            if ($kid === '') {
                continue;
            }
            $byIndex[(int)$owl['id']] = $kid;
        }
    }

    $byIid = [];
    foreach ($owlRows as $owl) {
        if (!$needsKey($owl)) {
            continue;
        }
        $owlPk = (int)($owl['id'] ?? 0);
        if ($owlPk > 0 && isset($byIndex[$owlPk])) {
            continue;
        }
        $iid = (int)($owl['inventory_item_id'] ?? 0);
        if ($iid <= 0) {
            continue;
        }
        foreach ($lines as $ln) {
            if (!is_array($ln)) {
                continue;
            }
            $li = (int)($ln['inventory_item_id'] ?? $ln['inventoryItemId'] ?? 0);
            if ($li !== $iid) {
                continue;
            }
            $kid = trim((string)($ln['line_id'] ?? $ln['orderLineId'] ?? $ln['lineId'] ?? ''));
            if ($kid !== '') {
                $byIid[$owlPk] = $kid;
            }
            break;
        }
    }

    $upd = $pdo->prepare('UPDATE order_warehouse_lines SET order_line_key = ?, updated_at = ? WHERE id = ?');
    $now = date('c');
    foreach ($byIndex + $byIid as $owlPk => $key) {
        if ($dryRun) {
            $out['owl_updated']++;
            continue;
        }
        $upd->execute([$key, $now, $owlPk]);
        $out['owl_updated']++;
    }

    return $out;
}

try {
    $pdo = getSqliteConnection();
} catch (Throwable $e) {
    fwrite(STDERR, 'SQLite: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$distinct = $pdo->query(
    'SELECT DISTINCT trim(order_id) AS oid FROM order_warehouse_lines WHERE trim(order_id) != \'\' ORDER BY oid'
)->fetchAll(PDO::FETCH_COLUMN);

$totalJson = 0;
$totalOwl = 0;
$pdo->beginTransaction();
try {
    foreach ($distinct as $oid) {
        $oid = trim((string)$oid);
        if ($oid === '') {
            continue;
        }
        $r = fixarivan_backfill_one_order($pdo, $oid, $dryRun);
        if ($r['json_updated']) {
            $totalJson++;
        }
        $totalOwl += $r['owl_updated'];
    }
    if ($dryRun) {
        $pdo->rollBack();
    } else {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$mode = $dryRun ? 'DRY-RUN (no writes)' : 'OK';
echo "{$mode}: orders with JSON patched: {$totalJson}, owl rows with order_line_key set: {$totalOwl}" . PHP_EOL;
exit(0);
