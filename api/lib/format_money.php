<?php
declare(strict_types=1);

/**
 * EUR display by UI language.
 * FI / RU: 300,00 €
 * EN: €300.00 (same grouping as number_format: space as thousands separator in this project)
 */
function fixarivan_format_money(float|string $amount, string $lang): string
{
    $n = is_numeric($amount) ? (float)$amount : 0.0;
    $l = strtolower(trim($lang));
    if (!in_array($l, ['ru', 'en', 'fi'], true)) {
        $l = 'ru';
    }
    $formatted = number_format($n, 2, $l === 'en' ? '.' : ',', ' ');

    return $l === 'en' ? ('€' . $formatted) : ($formatted . ' €');
}
