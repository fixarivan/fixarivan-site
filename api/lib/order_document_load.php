<?php
/**
 * Единая загрузка заказа для get_document и клиентского портала (TZ P2 блок 8): SQLite + подмешивание из JSON + enrich строк.
 */
declare(strict_types=1);

require_once __DIR__ . '/order_json_storage.php';
require_once __DIR__ . '/order_warehouse_sync.php';

/**
 * Поля supply в JSON заказа; в SQLite их может не быть — подмешиваем (как в Track/формах).
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function fixarivan_merge_order_json_overlay(array $row): array
{
    $docId = trim((string) ($row['document_id'] ?? ''));
    if ($docId === '') {
        return $row;
    }
    $path = fixarivan_orders_storage_dir() . DIRECTORY_SEPARATOR . $docId . '.json';
    if (!is_file($path)) {
        return $row;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return $row;
    }
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        return $row;
    }
    foreach (['supply_request', 'supply_urgency', 'supply_due_date', 'additional_info'] as $k) {
        $sqliteEmpty = !isset($row[$k]) || (string) $row[$k] === '';
        if ($sqliteEmpty && isset($j[$k]) && (string) $j[$k] !== '') {
            $row[$k] = $j[$k];
        }
    }

    return $row;
}

/**
 * Только файл storage/orders/{document_id}.json (fallback без SQLite).
 *
 * @return array<string, mixed>
 */
function fixarivan_load_order_from_json_file_only(string $documentId): array
{
    $documentId = trim($documentId);
    if ($documentId === '') {
        return [];
    }
    $path = fixarivan_orders_storage_dir() . DIRECTORY_SEPARATOR . $documentId . '.json';
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * Заказ как для api/get_document.php?type=order: SQLite приоритетно, иначе JSON-файл.
 *
 * @return array<string, mixed>
 */
function fixarivan_load_order_from_sqlite_or_json(PDO $pdo, string $documentId): array
{
    $documentId = trim($documentId);
    if ($documentId === '') {
        return [];
    }
    try {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE document_id = :id LIMIT 1');
        $stmt->execute([':id' => $documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && $row !== []) {
            $row = fixarivan_merge_order_json_overlay($row);
            $olj = $row['order_lines_json'] ?? '';
            if (is_string($olj) && $olj !== '') {
                $row['order_lines_json'] = fixarivan_enrich_order_lines_inventory_ids($pdo, $olj);
            }

            return $row;
        }
    } catch (Throwable $e) {
        // fallback JSON
    }

    return fixarivan_load_order_from_json_file_only($documentId);
}
