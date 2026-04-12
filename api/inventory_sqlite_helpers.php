<?php
declare(strict_types=1);

/**
 * Присваивает артикул FV-{id} всем позициям без SKU (единый формат для каталога и Track).
 *
 * @return int число обновлённых строк
 */
function fixarivan_inventory_ensure_missing_skus(PDO $pdo): int {
    $now = date('c');
    $stmt = $pdo->prepare(
        "UPDATE inventory_items SET sku = 'FV-' || id, updated_at = ?
         WHERE sku IS NULL OR TRIM(COALESCE(sku, '')) = ''"
    );
    $stmt->execute([$now]);

    return $stmt->rowCount();
}

/**
 * Aggregate warehouse metrics from SQLite (inventory_items + inventory_balances).
 */
function sqliteInventoryAggregateStats(PDO $pdo): array {
    $row = $pdo->query(
        "
        SELECT
            (SELECT COUNT(*) FROM inventory_items) AS total,
            (SELECT COUNT(*) FROM inventory_balances WHERE quantity > 0) AS in_stock,
            (
                SELECT COUNT(*) FROM inventory_balances b
                INNER JOIN inventory_items i ON i.id = b.item_id
                WHERE b.quantity > 0 AND b.quantity <= i.min_stock
            ) AS low_stock,
            (
                SELECT COALESCE(SUM(b.quantity * COALESCE(i.default_cost, 0)), 0)
                FROM inventory_balances b
                INNER JOIN inventory_items i ON i.id = b.item_id
            ) AS purchase_value,
            (
                SELECT COALESCE(SUM(b.quantity * COALESCE(i.sale_price, 0)), 0)
                FROM inventory_balances b
                INNER JOIN inventory_items i ON i.id = b.item_id
            ) AS sale_value,
            (
                SELECT COALESCE(SUM(ABS(m.quantity_delta)), 0)
                FROM inventory_movements m
                WHERE m.movement_type = 'sale'
            ) AS sold_qty,
            (
                SELECT COALESCE(SUM(ABS(m.quantity_delta) * COALESCE(m.unit_sale_price, i.sale_price, 0)), 0)
                FROM inventory_movements m
                LEFT JOIN inventory_items i ON i.id = m.item_id
                WHERE m.movement_type = 'sale'
            ) AS sold_revenue,
            (
                SELECT COALESCE(SUM(ABS(m.quantity_delta) * COALESCE(m.unit_cost, i.default_cost, 0)), 0)
                FROM inventory_movements m
                LEFT JOIN inventory_items i ON i.id = m.item_id
                WHERE m.movement_type = 'sale'
            ) AS sold_purchase
        "
    )->fetch(PDO::FETCH_ASSOC);

    $purchase = (float)($row['purchase_value'] ?? 0);
    $sale = (float)($row['sale_value'] ?? 0);
    $soldRevenue = (float)($row['sold_revenue'] ?? 0);
    $soldPurchase = (float)($row['sold_purchase'] ?? 0);

    return [
        'total' => (int)($row['total'] ?? 0),
        'in_stock' => (int)($row['in_stock'] ?? 0),
        'low_stock' => (int)($row['low_stock'] ?? 0),
        'value' => $purchase,
        'purchase_value' => $purchase,
        'sale_value' => $sale,
        'profit_potential' => $sale - $purchase,
        'sold_qty' => (float)($row['sold_qty'] ?? 0),
        'sold_revenue' => $soldRevenue,
        'sold_purchase' => $soldPurchase,
        'realized_margin' => $soldRevenue - $soldPurchase,
    ];
}
