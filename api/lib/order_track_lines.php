<?php
/**
 * Общая логика нормализации строк заказа для Track (TZ G: один источник на сервере).
 */
declare(strict_types=1);

/**
 * @param array<int, mixed> $lines
 * @return list<array<string, mixed>>
 */
function fixarivan_track_normalize_order_lines(array $lines): array
{
    $out = [];
    foreach ($lines as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $name = trim((string)($ln['name'] ?? $ln['title'] ?? ''));
        if ($name === '') {
            continue;
        }
        $qty = (float)($ln['qty'] ?? $ln['quantity'] ?? 1);
        if ($qty <= 0) {
            $qty = 1.0;
        }
        $purchase = (float)($ln['purchase'] ?? $ln['purchase_price'] ?? $ln['cost'] ?? 0);
        $sale = (float)($ln['sale'] ?? $ln['sale_price'] ?? $ln['price'] ?? 0);
        $sku = trim((string)($ln['sku'] ?? ''));
        $iid = (int)($ln['inventory_item_id'] ?? $ln['inventoryItemId'] ?? 0);
        $fromStock = $ln['from_stock'] ?? $ln['fromStock'] ?? false;
        $fromStock = $fromStock === true || $fromStock === 1 || $fromStock === '1'
            || (is_string($fromStock) && strtolower(trim($fromStock)) === 'true');
        $deduct = $ln['deductFromStock'] ?? $ln['deduct_from_stock'] ?? false;
        $deduct = $deduct === true || $deduct === 1 || $deduct === '1'
            || (is_string($deduct) && strtolower(trim($deduct)) === 'true');
        $status = trim((string)($ln['status'] ?? ''));
        if ($fromStock) {
            $deduct = false;
        }
        $lineIdRaw = trim((string)($ln['line_id'] ?? $ln['orderLineId'] ?? $ln['lineId'] ?? ''));
        $row = [
            'name' => $name,
            'qty' => $qty,
            'purchase' => $purchase,
            'sale' => $sale,
            'sku' => $sku !== '' ? $sku : null,
            'inventory_item_id' => $iid > 0 ? $iid : null,
            'from_stock' => $fromStock,
            'status' => $status !== '' ? $status : null,
            'line_id' => $lineIdRaw !== '' ? $lineIdRaw : null,
            '_deduct' => $deduct,
        ];
        $out[] = $row;
    }

    return $out;
}

/** @return array{0: float, 1: float} */
function fixarivan_track_totals_from_lines(array $lines): array
{
    $purchase = 0.0;
    $sale = 0.0;
    foreach ($lines as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $q = (float)($ln['qty'] ?? $ln['quantity'] ?? 1);
        if ($q <= 0) {
            $q = 1.0;
        }
        $purchase += (float)($ln['purchase'] ?? $ln['purchase_price'] ?? $ln['cost'] ?? 0) * $q;
        $sale += (float)($ln['sale'] ?? $ln['sale_price'] ?? $ln['price'] ?? 0) * $q;
    }

    return [$purchase, $sale];
}

function fixarivan_track_resolve_inventory_item_id(PDO $pdo, int $hintId, string $sku): int
{
    if ($hintId > 0) {
        $st = $pdo->prepare('SELECT id FROM inventory_items WHERE id = ? LIMIT 1');
        $st->execute([$hintId]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) {
            return $id;
        }
    }
    $sku = trim($sku);
    if ($sku === '') {
        return 0;
    }
    $st = $pdo->prepare('SELECT id FROM inventory_items WHERE TRIM(LOWER(sku)) = TRIM(LOWER(?)) LIMIT 1');
    $st->execute([$sku]);

    return (int)$st->fetchColumn();
}

function fixarivan_track_inventory_balance(PDO $pdo, int $itemId): float
{
    $st = $pdo->prepare('SELECT quantity FROM inventory_balances WHERE item_id = ?');
    $st->execute([$itemId]);

    return (float)$st->fetchColumn();
}
