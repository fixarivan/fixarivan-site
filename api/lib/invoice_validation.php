<?php
declare(strict_types=1);

/**
 * Нормализация и валидация счёта до сохранения.
 * Не дублирует расчёт mixed VAT — использует fixarivan_invoice_totals_from_items из invoice_center.
 */

require_once __DIR__ . '/invoice_center.php';

/**
 * Поддержка "25,5" и "25.5" (и пробелы/неразрывный пробел).
 */
function fixarivan_invoice_normalize_decimal_string(mixed $value): float
{
    if (is_int($value) || is_float($value)) {
        return (float)$value;
    }
    $s = trim((string)$value);
    $s = str_replace(["\xc2\xa0", "\xe2\x80\xaf", ' '], '', $s);
    $s = str_replace(',', '.', $s);
    if ($s === '' || $s === '-' || $s === '—') {
        return 0.0;
    }
    if (!is_numeric($s)) {
        return NAN;
    }

    return (float)$s;
}

/**
 * Приводит ставку к 0 или 25.5 при близком совпадении; иначе возвращает как есть (валидация отклонит).
 */
function fixarivan_invoice_coerce_vat_rate(float $rate): float
{
    if (is_nan($rate) || !is_finite($rate)) {
        return $rate;
    }
    if (abs($rate) < 0.0001) {
        return 0.0;
    }
    if (abs($rate - 25.5) < 0.05) {
        return 25.5;
    }

    return $rate;
}

/**
 * @param array<int, mixed> $items
 * @return list<array<string, mixed>>
 */
function fixarivan_invoice_normalize_items_array(array $items): array
{
    $out = [];
    foreach ($items as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string)($row['name'] ?? $row['description'] ?? ''));
        $qty = fixarivan_invoice_normalize_decimal_string($row['qty'] ?? $row['quantity'] ?? 0);
        $price = fixarivan_invoice_normalize_decimal_string($row['price'] ?? 0);
        $vatRaw = fixarivan_invoice_normalize_decimal_string($row['vat'] ?? $row['tax_rate'] ?? 0);
        $vat = fixarivan_invoice_coerce_vat_rate($vatRaw);
        $out[] = [
            'name' => $name,
            'description' => trim((string)($row['description'] ?? '')),
            'qty' => $qty,
            'price' => $price,
            'vat' => $vat,
            'tax_rate' => $vat,
        ];
    }

    return $out;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function fixarivan_invoice_normalize_input(array $input): array
{
    $out = $input;
    if (isset($input['items']) && is_array($input['items'])) {
        $out['items'] = fixarivan_invoice_normalize_items_array($input['items']);
    }

    return $out;
}

/**
 * @param array<string, mixed> $input
 * @param array<string, mixed> $existing Строка из БД или []
 * @return array{ok: bool, errors: list<array{code: string, message: string, field?: string, row?: int}>}
 */
function fixarivan_invoice_validate(array $input, array $existing = []): array
{
    $errors = [];
    $clientName = trim((string)($input['clientName'] ?? $input['client_name'] ?? ''));
    $phone = trim((string)($input['clientPhone'] ?? $input['client_phone'] ?? ''));
    $email = trim((string)($input['clientEmail'] ?? $input['client_email'] ?? ''));

    if ($clientName === '') {
        $errors[] = ['code' => 'client_name_required', 'message' => 'Client name is required', 'field' => 'client_name'];
    }
    if ($phone === '' && $email === '') {
        $errors[] = ['code' => 'contact_required', 'message' => 'Email or phone is required', 'field' => 'client_contact'];
    }

    $items = isset($input['items']) && is_array($input['items']) ? $input['items'] : [];
    $lines = fixarivan_invoice_filter_line_items($items);
    if ($lines === []) {
        $errors[] = ['code' => 'items_required', 'message' => 'At least one line item is required', 'field' => 'items'];
    }

    foreach ($items as $idx => $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string)($row['name'] ?? $row['description'] ?? ''));
        if ($name === '') {
            continue;
        }
        $qty = fixarivan_invoice_normalize_decimal_string($row['qty'] ?? $row['quantity'] ?? 0);
        $price = fixarivan_invoice_normalize_decimal_string($row['price'] ?? 0);
        $vatRaw = fixarivan_invoice_normalize_decimal_string($row['vat'] ?? $row['tax_rate'] ?? 0);
        $vat = fixarivan_invoice_coerce_vat_rate($vatRaw);

        if (is_nan($qty) || !is_finite($qty)) {
            $errors[] = ['code' => 'item_qty_invalid', 'message' => 'Invalid quantity', 'field' => 'items', 'row' => (int)$idx];
        } elseif ($qty <= 0) {
            $errors[] = ['code' => 'item_qty_positive', 'message' => 'Quantity must be > 0', 'field' => 'items', 'row' => (int)$idx];
        }
        if (is_nan($price) || !is_finite($price)) {
            $errors[] = ['code' => 'item_price_invalid', 'message' => 'Invalid price', 'field' => 'items', 'row' => (int)$idx];
        } elseif ($price < 0) {
            $errors[] = ['code' => 'item_price_negative', 'message' => 'Price must be >= 0', 'field' => 'items', 'row' => (int)$idx];
        }
        if (is_nan($vat) || !is_finite($vat)) {
            $errors[] = ['code' => 'item_vat_invalid', 'message' => 'Invalid VAT', 'field' => 'items', 'row' => (int)$idx];
        } elseif (abs($vat) > 0.0001 && abs($vat - 25.5) > 0.05) {
            $errors[] = ['code' => 'item_vat_allowed', 'message' => 'VAT must be 0 or 25.5', 'field' => 'items', 'row' => (int)$idx];
        }
    }

    if ($lines !== []) {
        $tot = fixarivan_invoice_totals_from_items($items);
        foreach (['subtotal', 'tax_amount', 'total_amount'] as $k) {
            $v = $tot[$k] ?? 0.0;
            if (is_nan($v) || !is_finite($v) || $v < 0) {
                $errors[] = ['code' => 'totals_invalid', 'message' => 'Invalid totals', 'field' => 'totals'];
                break;
            }
        }
    }

    $dueRaw = (string)($input['dueDate'] ?? $input['due_date'] ?? '');
    $dueRaw = trim($dueRaw);
    if ($dueRaw !== '') {
        $invoiceDay = null;
        if (!empty($existing['date_created'])) {
            $ts = strtotime((string)$existing['date_created']);
            if ($ts !== false) {
                $invoiceDay = date('Y-m-d', $ts);
            }
        }
        if ($invoiceDay === null) {
            $invoiceDay = (new DateTimeImmutable('now', new DateTimeZone('Europe/Helsinki')))->format('Y-m-d');
        }
        $dueTs = strtotime($dueRaw);
        if ($dueTs === false) {
            $errors[] = ['code' => 'due_date_invalid', 'message' => 'Invalid due date', 'field' => 'due_date'];
        } else {
            $dueDay = date('Y-m-d', $dueTs);
            if ($dueDay < $invoiceDay) {
                $errors[] = ['code' => 'due_before_invoice', 'message' => 'Due date must be on or after invoice date', 'field' => 'due_date'];
            }
        }
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
    ];
}
