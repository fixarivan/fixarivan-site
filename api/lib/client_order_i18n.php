<?php
declare(strict_types=1);

/**
 * Единый словарь клиентских переводов (ru / fi / en).
 * Админский интерфейс остаётся на русском; эти строки — для портала, документов и вставки в заказ.
 */

function fixarivan_client_order_normalize_lang(?string $lang): string
{
    $l = strtolower(trim((string) ($lang ?? 'ru')));

    return in_array($l, ['ru', 'en', 'fi'], true) ? $l : 'ru';
}

/**
 * @return array<string, array{ru:string,fi:string,en:string}>
 */
function fixarivan_client_device_type_labels(): array
{
    return [
        'phone' => ['ru' => 'Телефон', 'fi' => 'Puhelin', 'en' => 'Phone'],
        'laptop' => ['ru' => 'Ноутбук', 'fi' => 'Kannettava tietokone', 'en' => 'Laptop'],
        'tablet' => ['ru' => 'Планшет', 'fi' => 'Tabletti', 'en' => 'Tablet'],
        'console' => ['ru' => 'Консоль', 'fi' => 'Pelikonsoli', 'en' => 'Game console'],
        'other' => ['ru' => 'Другое', 'fi' => 'Muu', 'en' => 'Other'],
    ];
}

function fixarivan_client_device_type_label(?string $code, ?string $lang): string
{
    $raw = trim((string) ($code ?? ''));
    if ($raw === '') {
        return '';
    }

    $l = fixarivan_client_order_normalize_lang($lang);
    $key = strtolower($raw);
    $map = fixarivan_client_device_type_labels();
    if (isset($map[$key][$l])) {
        return $map[$key][$l];
    }

    foreach ($map as $labels) {
        foreach ($labels as $label) {
            if (strcasecmp($label, $raw) === 0) {
                return $labels[$l] ?? $raw;
            }
        }
    }

    return $raw;
}
