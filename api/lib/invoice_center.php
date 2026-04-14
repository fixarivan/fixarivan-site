<?php
declare(strict_types=1);

require_once __DIR__ . '/order_center.php';

/**
 * ru / fi / en — сохраняется в invoices.language и задаёт язык PDF/viewer.
 */
/**
 * Способ оплаты счёта (как у квитанций): holvi_terminal, cash, …
 */
function fixarivan_normalize_invoice_payment_method(string $raw): string
{
    $raw = strtolower(trim($raw));
    $map = [
        'transfer' => 'bank_transfer',
        'mobile' => 'mobilepay',
    ];
    $m = $map[$raw] ?? $raw;
    $allowed = ['holvi_terminal', 'cash', 'bank_transfer', 'card', 'mobilepay', 'other'];

    return in_array($m, $allowed, true) ? $m : 'other';
}

function fixarivan_normalize_invoice_language(array $input, array $existing = []): string
{
    $raw = $input['language'] ?? $existing['language'] ?? 'ru';
    $l = strtolower(trim((string)$raw));

    return in_array($l, ['ru', 'en', 'fi'], true) ? $l : 'ru';
}

function fixarivan_next_invoice_id(PDO $pdo, ?DateTimeInterface $now = null): string
{
    $dt = $now ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Helsinki'));
    $year = $dt->format('Y');
    $prefix = 'FV-' . $year . '-';

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT invoice_id
             FROM invoices
             WHERE invoice_id LIKE :prefix
             ORDER BY invoice_id DESC
             LIMIT 1'
        );
        $stmt->execute([':prefix' => $prefix . '%']);
        $last = (string)($stmt->fetchColumn() ?: '');
        $seq = 1;
        if ($last !== '' && preg_match('/^FV-\d{4}-(\d{4})$/', $last, $m)) {
            $seq = ((int)$m[1]) + 1;
        }
        $invoiceId = $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
        $pdo->commit();
        return $invoiceId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Строки счёта с непустым наименованием.
 *
 * @param array<int, mixed> $items
 * @return list<array<string,mixed>>
 */
function fixarivan_invoice_filter_line_items(array $items): array
{
    $out = [];
    foreach ($items as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string)($row['name'] ?? $row['description'] ?? ''));
        if ($name === '') {
            continue;
        }
        $out[] = $row;
    }
    return $out;
}

/**
 * Итоги по строкам: net (qty×price), налог по ставке строки, без усреднения %.
 *
 * @param array<int, mixed> $items
 * @return array{subtotal: float, tax_amount: float, total_amount: float}
 */
function fixarivan_invoice_totals_from_items(array $items): array
{
    $lines = fixarivan_invoice_filter_line_items($items);
    $subtotal = 0.0;
    $taxAmount = 0.0;
    foreach ($lines as $row) {
        $qty = (float)($row['qty'] ?? $row['quantity'] ?? 0);
        $price = (float)($row['price'] ?? 0);
        $vat = (float)($row['vat'] ?? $row['tax_rate'] ?? 0);
        $base = $qty * $price;
        $tax = $base * ($vat / 100.0);
        $subtotal += $base;
        $taxAmount += $tax;
    }

    return [
        'subtotal' => $subtotal,
        'tax_amount' => $taxAmount,
        'total_amount' => $subtotal + $taxAmount,
    ];
}

/**
 * Группировка по ставке НДС (для breakdown в PDF/UI).
 *
 * @param array<int, mixed> $items
 * @return list<array{rate: float, base: float, tax: float}>
 */
function fixarivan_invoice_vat_groups_by_rate(array $items): array
{
    $lines = fixarivan_invoice_filter_line_items($items);
    $groups = [];
    foreach ($lines as $row) {
        $qty = (float)($row['qty'] ?? $row['quantity'] ?? 0);
        $price = (float)($row['price'] ?? 0);
        $vat = (float)($row['vat'] ?? $row['tax_rate'] ?? 0);
        $rate = round($vat, 4);
        $base = $qty * $price;
        $tax = $base * ($vat / 100.0);
        if (!isset($groups[$rate])) {
            $groups[$rate] = ['rate' => $rate, 'base' => 0.0, 'tax' => 0.0];
        }
        $groups[$rate]['base'] += $base;
        $groups[$rate]['tax'] += $tax;
    }
    ksort($groups, SORT_NUMERIC);

    return array_values($groups);
}

/**
 * @return array<string,mixed>
 */
function fixarivan_normalize_invoice_record(array $input, array $existing = []): array
{
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Helsinki'));
    $items = $input['items'] ?? $input['services'] ?? $existing['items'] ?? [];
    if (!is_array($items)) {
        $items = [];
    }

    $lineItems = fixarivan_invoice_filter_line_items($items);
    if ($lineItems !== []) {
        $tot = fixarivan_invoice_totals_from_items($items);
        $subtotal = $tot['subtotal'];
        $taxAmount = $tot['tax_amount'];
        $totalAmount = $tot['total_amount'];
        $taxRate = 0.0;
    } else {
        $subtotal = isset($input['subtotal']) ? (float)$input['subtotal'] : (isset($existing['subtotal']) ? (float)$existing['subtotal'] : 0.0);
        $taxRate = isset($input['tax_rate']) ? (float)$input['tax_rate'] : (isset($input['taxRate']) ? (float)$input['taxRate'] : (isset($existing['tax_rate']) ? (float)$existing['tax_rate'] : 0.0));
        $taxAmount = isset($input['tax_amount']) ? (float)$input['tax_amount'] : (isset($input['taxAmount']) ? (float)$input['taxAmount'] : (isset($existing['tax_amount']) ? (float)$existing['tax_amount'] : 0.0));
        $totalAmount = isset($input['total_amount']) ? (float)$input['total_amount'] : (isset($input['totalAmount']) ? (float)$input['totalAmount'] : (isset($existing['total_amount']) ? (float)$existing['total_amount'] : ($subtotal + $taxAmount)));
    }

    return [
        'document_id' => trim((string)($input['documentId'] ?? $input['document_id'] ?? $existing['document_id'] ?? '')),
        'invoice_id' => trim((string)($input['invoiceId'] ?? $input['invoice_id'] ?? $existing['invoice_id'] ?? '')),
        'order_id' => trim((string)($input['orderId'] ?? $input['order_id'] ?? $existing['order_id'] ?? '')),
        'client_id' => trim((string)($input['clientId'] ?? $input['client_id'] ?? $existing['client_id'] ?? '')),
        'date_created' => (string)($existing['date_created'] ?? $now->format('c')),
        'date_updated' => $now->format('c'),
        'due_date' => (string)($input['dueDate'] ?? $input['due_date'] ?? $existing['due_date'] ?? $now->modify('+14 day')->format('Y-m-d')),
        'status' => trim((string)($input['status'] ?? $existing['status'] ?? 'draft')),
        'language' => fixarivan_normalize_invoice_language($input, $existing),
        'client_name' => trim((string)($input['clientName'] ?? $input['client_name'] ?? $existing['client_name'] ?? '')),
        'client_phone' => fixarivan_normalize_phone((string)($input['clientPhone'] ?? $input['client_phone'] ?? $existing['client_phone'] ?? '')),
        'client_email' => fixarivan_safe_lower((string)($input['clientEmail'] ?? $input['client_email'] ?? $existing['client_email'] ?? '')),
        'service_object' => trim((string)($input['serviceObject'] ?? $input['service_object'] ?? $existing['service_object'] ?? '')),
        'service_address' => trim((string)($input['serviceAddress'] ?? $input['service_address'] ?? $existing['service_address'] ?? '')),
        'items' => $items,
        'subtotal' => $subtotal,
        'tax_rate' => $taxRate,
        'tax_amount' => $taxAmount,
        'total_amount' => $totalAmount,
        'payment_terms' => trim((string)($input['paymentTerms'] ?? $input['payment_terms'] ?? $existing['payment_terms'] ?? '14 days')),
        'note' => trim((string)($input['note'] ?? $input['notes'] ?? $existing['note'] ?? '')),
        /** Путь от корня сайта, напр. storage/invoices_media/INV-....png */
        'invoice_logo' => trim((string)($existing['invoice_logo'] ?? '')),
        'payment_date' => trim((string)($input['paymentDate'] ?? $input['payment_date'] ?? $existing['payment_date'] ?? '')),
        'payment_method' => fixarivan_normalize_invoice_payment_method(
            (string)($input['paymentMethod'] ?? $input['payment_method'] ?? $existing['payment_method'] ?? '')
        ),
        'raw_json' => $input,
    ];
}

/**
 * При статусе paid подставить дату оплаты и способ, если мастер не указал.
 *
 * @param array<string,mixed> $record
 * @return array<string,mixed>
 */
function fixarivan_invoice_finalize_payment_fields(array $record, DateTimeImmutable $now): array
{
    $status = strtolower(trim((string)($record['status'] ?? '')));
    if ($status === 'paid' && trim((string)($record['payment_date'] ?? '')) === '') {
        $record['payment_date'] = $now->format('Y-m-d');
    }

    return $record;
}

/**
 * Сохраняет картинку счёта из data URL (PNG/JPEG/GIF/WebP), не больше ~2.5 МБ декодированных данных.
 * Возвращает относительный путь от корня проекта или null.
 */
function fixarivan_invoice_save_logo_from_data_url(string $documentId, string $dataUrl): ?string
{
    $documentId = preg_replace('/[^A-Za-z0-9._-]/', '', $documentId) ?? '';
    if ($documentId === '') {
        return null;
    }
    if (!preg_match('#^data:image/(png|jpeg|jpg|gif|webp);base64,(.+)$#i', trim($dataUrl), $m)) {
        return null;
    }
    $raw = base64_decode($m[2], true);
    if ($raw === false || $raw === '' || strlen($raw) > 2_500_000) {
        return null;
    }
    $ext = strtolower($m[1]);
    if ($ext === 'jpeg') {
        $ext = 'jpg';
    }
    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'invoices_media';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }
    $rel = 'storage/invoices_media/' . $documentId . '.' . $ext;
    $abs = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (file_put_contents($abs, $raw, LOCK_EX) === false) {
        return null;
    }
    return $rel;
}

function fixarivan_invoice_delete_logo_file(?string $relativePath): void
{
    $relativePath = trim((string)$relativePath);
    if ($relativePath === '' || strpos($relativePath, '..') !== false) {
        return;
    }
    if (!str_starts_with(str_replace('\\', '/', $relativePath), 'storage/invoices_media/')) {
        return;
    }
    $abs = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    if (is_file($abs)) {
        @unlink($abs);
    }
}
