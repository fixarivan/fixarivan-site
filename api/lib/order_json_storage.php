<?php
/**
 * Единое сохранение JSON заказа на диск (TZ P2 блок 6: одна реализация, не дубли в эндпоинтах).
 */
declare(strict_types=1);

function fixarivan_orders_storage_dir(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'orders';
}

function fixarivan_orders_tokens_storage_dir(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'orders_tokens';
}

function fixarivan_orders_storage_ensure_dir(string $dir): void
{
    if (is_dir($dir)) {
        return;
    }
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create dir: {$dir}");
    }
}

/**
 * Сохраняет {documentId}.json и дублирует в {clientToken}.json при непустом токене.
 *
 * @param array<string, mixed> $record
 */
function fixarivan_save_order_json_files(array $record, string $documentId, string $clientToken): void
{
    $documentId = trim($documentId);
    if ($documentId === '') {
        throw new RuntimeException('documentId пустой');
    }
    $ordersDir = fixarivan_orders_storage_dir();
    fixarivan_orders_storage_ensure_dir($ordersDir);
    $jsonPath = $ordersDir . DIRECTORY_SEPARATOR . $documentId . '.json';
    $encoded = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($encoded === false || file_put_contents($jsonPath, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось сохранить JSON заказа');
    }
    $clientToken = trim($clientToken);
    if ($clientToken === '') {
        return;
    }
    $tokensDir = fixarivan_orders_tokens_storage_dir();
    fixarivan_orders_storage_ensure_dir($tokensDir);
    $tokenPath = $tokensDir . DIRECTORY_SEPARATOR . $clientToken . '.json';
    file_put_contents($tokenPath, $encoded, LOCK_EX);
}
