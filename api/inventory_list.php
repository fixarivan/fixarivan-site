<?php
/**
 * Список складских позиций с актуальным остатком (SQLite).
 * GET — список; POST — создать позицию (MVP).
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('GET, POST, PATCH, OPTIONS', 'Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';
require_once __DIR__ . '/lib/security_settings.php';

require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/inventory_sqlite_helpers.php';

function jsonOut(bool $success, ?string $message, $data, array $errors = []): void {
    echo json_encode(
        [
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
        ],
        JSON_UNESCAPED_UNICODE
    );
}

/** @param list<string> $inputKeys */
function inv_pick_float(array $input, array $row, array $inputKeys, string $rowKey, float $default): float {
    foreach ($inputKeys as $k) {
        if (array_key_exists($k, $input) && $input[$k] !== '' && $input[$k] !== null) {
            return (float)$input[$k];
        }
    }
    if (isset($row[$rowKey]) && $row[$rowKey] !== '' && $row[$rowKey] !== null) {
        return (float)$row[$rowKey];
    }

    return $default;
}

$pdo = null;
$sqliteErr = null;
try {
    $pdo = getSqliteConnection();
} catch (Throwable $e) {
    error_log('inventory_list SQLite: ' . $e->getMessage());
    $sqliteErr = $e->getMessage();
}

if ($pdo === null) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        jsonOut(true, null, ['items' => [], 'sqlite_available' => false], []);
        exit;
    }
    jsonOut(false, $sqliteErr ?? 'SQLite недоступен (включите расширение pdo_sqlite в PHP)', null, [$sqliteErr ?? 'sqlite']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        fixarivan_inventory_ensure_missing_skus($pdo);
    } catch (Throwable $e) {
        error_log('inventory ensure SKU: ' . $e->getMessage());
    }
    $category = isset($_GET['category']) ? trim((string)$_GET['category']) : '';
    $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    $idExact = isset($_GET['id']) ? trim((string)$_GET['id']) : '';

    $sql = '
        SELECT i.id, i.sku, i.name, i.category, i.compatibility, i.unit, i.min_stock, i.default_cost,
               COALESCE(i.sale_price, 0) AS sale_price,
               i.location,
               i.notes,
               i.created_at, i.updated_at,
               COALESCE(b.quantity, 0) AS quantity
        FROM inventory_items i
        LEFT JOIN inventory_balances b ON b.item_id = i.id
        WHERE 1=1
    ';
    $params = [];
    if ($idExact !== '' && ctype_digit($idExact)) {
        $sql .= ' AND i.id = ?';
        $params[] = (int)$idExact;
    }
    if ($category !== '') {
        $sql .= ' AND i.category = ?';
        $params[] = $category;
    }
    if ($q !== '' && ($idExact === '' || !ctype_digit($idExact))) {
        $like = '%' . $q . '%';
        $sql .= ' AND (
            i.name LIKE ?
            OR IFNULL(i.sku, \'\') LIKE ?
            OR IFNULL(i.compatibility, \'\') LIKE ?
        )';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= ' ORDER BY i.name COLLATE NOCASE';
    if ($q !== '' && ($idExact === '' || !ctype_digit($idExact))) {
        $limitQ = isset($_GET['limit']) ? (int)$_GET['limit'] : 40;
        if ($limitQ < 1) {
            $limitQ = 40;
        }
        if ($limitQ > 100) {
            $limitQ = 100;
        }
        $sql .= ' LIMIT ' . $limitQ;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    jsonOut(true, null, ['items' => $items]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw ?: '[]', true);
    if (!is_array($input)) {
        jsonOut(false, 'Некорректный JSON', null, ['invalid_json']);
        exit;
    }

    $action = strtolower(trim((string)($input['action'] ?? '')));
    if ($action === 'delete') {
        $deletePassword = trim((string)($input['delete_password'] ?? ''));
        if (!fixarivan_verify_delete_password($deletePassword)) {
            jsonOut(false, 'Неверный пароль удаления', null, ['delete_password_invalid']);
            exit;
        }
        $deleteId = isset($input['id']) ? (int)$input['id'] : 0;
        if ($deleteId <= 0) {
            jsonOut(false, 'Укажите id', null, ['id']);
            exit;
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM inventory_movements WHERE item_id = ?')->execute([$deleteId]);
            $pdo->prepare('DELETE FROM inventory_balances WHERE item_id = ?')->execute([$deleteId]);
            $stmt = $pdo->prepare('DELETE FROM inventory_items WHERE id = ?');
            $stmt->execute([$deleteId]);
            $deleted = $stmt->rowCount();
            $pdo->commit();
            if ($deleted <= 0) {
                jsonOut(false, 'Позиция не найдена', null, ['not_found']);
                exit;
            }
            jsonOut(true, 'Позиция удалена', ['deleted' => $deleteId]);
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            jsonOut(false, 'Ошибка удаления: ' . $e->getMessage(), null, [$e->getMessage()]);
            exit;
        }
    }

    // Обновление тем же POST (часть хостингов не пропускает PATCH).
    $updateId = isset($input['id']) ? (int)$input['id'] : 0;
    if ($updateId > 0) {
        $st = $pdo->prepare('SELECT * FROM inventory_items WHERE id = ?');
        $st->execute([$updateId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            jsonOut(false, 'Позиция не найдена', null, ['not_found']);
            exit;
        }

        $name = isset($input['name']) ? trim((string)$input['name']) : (string)$row['name'];
        if ($name === '') {
            jsonOut(false, 'Укажите name', null, ['name_required']);
            exit;
        }

        $sku = array_key_exists('sku', $input) ? (trim((string)$input['sku']) ?: null) : ($row['sku'] ?? null);
        if ($sku === null || $sku === '') {
            $sku = 'FV-' . $updateId;
        }
        $category = array_key_exists('category', $input) ? (trim((string)$input['category']) ?: null) : ($row['category'] ?? null);
        $compatibility = array_key_exists('compatibility', $input) ? (trim((string)$input['compatibility']) ?: null) : ($row['compatibility'] ?? null);
        $unit = isset($input['unit']) ? trim((string)$input['unit']) : (string)($row['unit'] ?? 'pcs');
        $minStock = inv_pick_float($input, $row, ['min_stock', 'minStock'], 'min_stock', (float)($row['min_stock'] ?? 0));
        $defaultCost = inv_pick_float($input, $row, ['default_cost', 'costPrice', 'purchase_price'], 'default_cost', (float)($row['default_cost'] ?? 0));
        $salePrice = inv_pick_float($input, $row, ['sale_price', 'sellPrice', 'selling_price'], 'sale_price', (float)($row['sale_price'] ?? 0));
        $notes = array_key_exists('notes', $input) ? (trim((string)$input['notes']) ?: null) : ($row['notes'] ?? null);
        $location = array_key_exists('location', $input)
            ? (trim((string)$input['location']) ?: null)
            : (isset($row['location']) ? (trim((string)$row['location']) ?: null) : null);

        $now = date('c');
        $stmt = $pdo->prepare(
            'UPDATE inventory_items SET
                sku = :sku,
                name = :name,
                category = :category,
                compatibility = :compatibility,
                unit = :unit,
                min_stock = :min_stock,
                default_cost = :default_cost,
                sale_price = :sale_price,
                location = :location,
                notes = :notes,
                updated_at = :updated_at
             WHERE id = :id'
        );
        try {
            $stmt->execute([
                ':sku' => $sku,
                ':name' => $name,
                ':category' => $category,
                ':compatibility' => $compatibility,
                ':unit' => $unit !== '' ? $unit : 'pcs',
                ':min_stock' => $minStock,
                ':default_cost' => $defaultCost,
                ':sale_price' => $salePrice,
                ':location' => $location,
                ':notes' => $notes,
                ':updated_at' => $now,
                ':id' => $updateId,
            ]);
        } catch (Throwable $e) {
            error_log('inventory_list UPDATE: ' . $e->getMessage());
            jsonOut(false, 'Не удалось сохранить: ' . $e->getMessage(), null, [$e->getMessage()]);
            exit;
        }
        jsonOut(true, null, ['item_id' => $updateId]);
        exit;
    }

    $name = trim((string)($input['name'] ?? ''));
    if ($name === '') {
        jsonOut(false, 'Укажите name', null, ['name_required']);
        exit;
    }

    $now = date('c');
    $emptyRow = [];
    $minStockIns = inv_pick_float($input, $emptyRow, ['min_stock', 'minStock'], 'min_stock', 0);
    $defaultCostIns = inv_pick_float($input, $emptyRow, ['default_cost', 'costPrice', 'purchase_price'], 'default_cost', 0);
    $salePriceIns = inv_pick_float($input, $emptyRow, ['sale_price', 'sellPrice', 'selling_price'], 'sale_price', 0);
    $locationIns = isset($input['location']) ? (trim((string)$input['location']) ?: null) : null;

    $skuIn = isset($input['sku']) ? trim((string)$input['sku']) : '';
    $skuForInsert = $skuIn !== '' ? $skuIn : null;

    $stmt = $pdo->prepare(
        'INSERT INTO inventory_items (sku, name, category, compatibility, unit, min_stock, default_cost, sale_price, location, notes, created_at, updated_at)
         VALUES (:sku, :name, :category, :compatibility, :unit, :min_stock, :default_cost, :sale_price, :location, :notes, :created_at, :updated_at)'
    );
    try {
        $stmt->execute([
            ':sku' => $skuForInsert,
            ':name' => $name,
            ':category' => isset($input['category']) ? (trim((string)$input['category']) ?: null) : null,
            ':compatibility' => isset($input['compatibility']) ? (trim((string)$input['compatibility']) ?: null) : null,
            ':unit' => isset($input['unit']) ? trim((string)$input['unit']) : 'pcs',
            ':min_stock' => $minStockIns,
            ':default_cost' => $defaultCostIns,
            ':sale_price' => $salePriceIns,
            ':location' => $locationIns,
            ':notes' => isset($input['notes']) ? (trim((string)$input['notes']) ?: null) : null,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    } catch (Throwable $e) {
        error_log('inventory_list INSERT: ' . $e->getMessage());
        jsonOut(false, 'Не удалось создать позицию: ' . $e->getMessage(), null, [$e->getMessage()]);
        exit;
    }
    $id = (int)$pdo->lastInsertId();
    if ($id <= 0) {
        $id = (int)$pdo->query('SELECT last_insert_rowid()')->fetchColumn();
    }

    $finalSku = $skuForInsert;
    if ($skuForInsert === null) {
        $finalSku = 'FV-' . $id;
        try {
            $pdo->prepare('UPDATE inventory_items SET sku = ?, updated_at = ? WHERE id = ?')->execute([$finalSku, $now, $id]);
        } catch (Throwable $e) {
            error_log('inventory_list auto-sku: ' . $e->getMessage());
        }
    }

    jsonOut(true, null, ['item_id' => $id, 'sku' => (string) $finalSku]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw ?: '[]', true);
    if (!is_array($input)) {
        jsonOut(false, 'Некорректный JSON', null, ['invalid_json']);
        exit;
    }

    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id <= 0) {
        jsonOut(false, 'Укажите id', null, ['id']);
        exit;
    }

    $st = $pdo->prepare('SELECT * FROM inventory_items WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        jsonOut(false, 'Позиция не найдена', null, ['not_found']);
        exit;
    }

    $name = isset($input['name']) ? trim((string)$input['name']) : (string)$row['name'];
    if ($name === '') {
        jsonOut(false, 'Укажите name', null, ['name_required']);
        exit;
    }

    $sku = array_key_exists('sku', $input) ? (trim((string)$input['sku']) ?: null) : ($row['sku'] ?? null);
    if ($sku === null || $sku === '') {
        $sku = 'FV-' . $id;
    }
    $category = array_key_exists('category', $input) ? (trim((string)$input['category']) ?: null) : ($row['category'] ?? null);
    $compatibility = array_key_exists('compatibility', $input) ? (trim((string)$input['compatibility']) ?: null) : ($row['compatibility'] ?? null);
    $unit = isset($input['unit']) ? trim((string)$input['unit']) : (string)($row['unit'] ?? 'pcs');
    $minStock = inv_pick_float($input, $row, ['min_stock', 'minStock'], 'min_stock', (float)($row['min_stock'] ?? 0));
    $defaultCost = inv_pick_float($input, $row, ['default_cost', 'costPrice', 'purchase_price'], 'default_cost', (float)($row['default_cost'] ?? 0));
    $salePrice = inv_pick_float($input, $row, ['sale_price', 'sellPrice', 'selling_price'], 'sale_price', (float)($row['sale_price'] ?? 0));
    $notes = array_key_exists('notes', $input) ? (trim((string)$input['notes']) ?: null) : ($row['notes'] ?? null);
    $location = array_key_exists('location', $input)
        ? (trim((string)$input['location']) ?: null)
        : (isset($row['location']) ? (trim((string)$row['location']) ?: null) : null);

    $now = date('c');
    $stmt = $pdo->prepare(
        'UPDATE inventory_items SET
            sku = :sku,
            name = :name,
            category = :category,
            compatibility = :compatibility,
            unit = :unit,
            min_stock = :min_stock,
            default_cost = :default_cost,
            sale_price = :sale_price,
            location = :location,
            notes = :notes,
            updated_at = :updated_at
         WHERE id = :id'
    );
    $stmt->execute([
        ':sku' => $sku,
        ':name' => $name,
        ':category' => $category,
        ':compatibility' => $compatibility,
        ':unit' => $unit !== '' ? $unit : 'pcs',
        ':min_stock' => $minStock,
        ':default_cost' => $defaultCost,
        ':sale_price' => $salePrice,
        ':location' => $location,
        ':notes' => $notes,
        ':updated_at' => $now,
        ':id' => $id,
    ]);

    jsonOut(true, null, ['item_id' => $id]);
    exit;
}

jsonOut(false, 'Метод не поддерживается', null, []);
