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
 * Разбивка по категориям по всем карточкам (не зависит от фильтра/лимита списка на клиенте).
 *
 * @return list<array{category:string,card_count:int,qty:float,purchase_value:float,sale_value:float}>
 */
function sqliteInventoryCategoryBreakdown(PDO $pdo): array {
    $sql = "
        SELECT
            COALESCE(NULLIF(TRIM(i.category), ''), 'other') AS category,
            COUNT(*) AS card_count,
            SUM(COALESCE(b.quantity, 0)) AS qty,
            SUM(COALESCE(b.quantity, 0) * COALESCE(i.default_cost, 0)) AS purchase_value,
            SUM(COALESCE(b.quantity, 0) * COALESCE(i.sale_price, 0)) AS sale_value
        FROM inventory_items i
        LEFT JOIN inventory_balances b ON b.item_id = i.id
        GROUP BY COALESCE(NULLIF(TRIM(i.category), ''), 'other')
        ORDER BY purchase_value DESC
    ";
    $stmt = $pdo->query($sql);
    if ($stmt === false) {
        return [];
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'category' => (string)($r['category'] ?? 'other'),
            'card_count' => (int)($r['card_count'] ?? 0),
            'qty' => (float)($r['qty'] ?? 0),
            'purchase_value' => (float)($r['purchase_value'] ?? 0),
            'sale_value' => (float)($r['sale_value'] ?? 0),
        ];
    }

    return $out;
}

/**
 * Aggregate warehouse metrics from SQLite (inventory_items + inventory_balances).
 * Суммы считаются от всех карточек (LEFT JOIN), чтобы позиции без строки в balances не выпадали из оценки.
 */
function sqliteInventoryAggregateStats(PDO $pdo): array {
    $row = $pdo->query(
        "
        SELECT
            (SELECT COUNT(*) FROM inventory_items) AS total,
            (
                SELECT COUNT(*)
                FROM inventory_items i
                LEFT JOIN inventory_balances b ON b.item_id = i.id
                WHERE COALESCE(b.quantity, 0) > 0
            ) AS in_stock,
            (
                SELECT COUNT(*)
                FROM inventory_items i
                LEFT JOIN inventory_balances b ON b.item_id = i.id
                WHERE COALESCE(b.quantity, 0) > 0
                  AND COALESCE(b.quantity, 0) <= COALESCE(i.min_stock, 0)
            ) AS low_stock,
            (
                SELECT COALESCE(SUM(COALESCE(b.quantity, 0) * COALESCE(i.default_cost, 0)), 0)
                FROM inventory_items i
                LEFT JOIN inventory_balances b ON b.item_id = i.id
            ) AS purchase_value,
            (
                SELECT COALESCE(SUM(COALESCE(b.quantity, 0) * COALESCE(i.sale_price, 0)), 0)
                FROM inventory_items i
                LEFT JOIN inventory_balances b ON b.item_id = i.id
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
