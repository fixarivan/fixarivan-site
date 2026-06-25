<?php
declare(strict_types=1);

function fixarivan_company_profile_path(): string {
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'company_profile.json';
}

/**
 * @return array<string,string>
 */
function fixarivan_company_profile_defaults(): array {
    return [
        'company_name' => 'FixariVan',
        'company_phone' => '+358 44 954 5263',
        'company_email' => 'fixarivan@gmail.com',
        'company_website' => 'www.fixarivan.fi',
        'company_address' => 'Turku, Finland',
        'y_tunnus' => '3526510-5',
        'iban' => '',
        'bic' => '',
        'bank_name' => '',
        /** Относительный путь от корня сайта, напр. assets/company_logo.png */
        'company_logo' => '',
        /** Ссылка на отзыв Google (клиентский портал, завершённые заказы). Пусто — блок скрыт. */
        'google_review_url' => 'https://share.google/saZoUi8tRidx6Y7kc',
    ];
}

/**
 * @return array<string,string>
 */
function fixarivan_company_profile_load(): array {
    $profile = fixarivan_company_profile_defaults();
    $path = fixarivan_company_profile_path();
    if (!is_readable($path)) {
        return $profile;
    }
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $profile;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $profile;
    }
    foreach ($profile as $key => $value) {
        if (array_key_exists($key, $decoded)) {
            $profile[$key] = trim((string)$decoded[$key]);
        }
    }
    return $profile;
}

/**
 * @param array<string,mixed> $input
 * @return array<string,string>
 */
function fixarivan_company_profile_save(array $input): array {
    $current = fixarivan_company_profile_load();
    $saved = [];
    foreach ($current as $key => $value) {
        $saved[$key] = trim((string)($input[$key] ?? $value));
    }

    $dir = dirname(fixarivan_company_profile_path());
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Нет каталога storage/');
    }

    $payload = json_encode($saved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($payload === false) {
        throw new RuntimeException('Ошибка сериализации профиля компании');
    }

    $path = fixarivan_company_profile_path();
    if (file_put_contents($path, $payload, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось сохранить профиль компании');
    }
    @chmod($path, 0640);

    return $saved;
}

/** Корень сайта (каталог с index.php). */
function fixarivan_company_site_root(): string {
    return dirname(__DIR__, 2);
}

/**
 * Относительный путь к загруженному логотипу, если файл на диске есть.
 */
function fixarivan_brand_logo_rel_path(): string {
    $rel = trim((string)(fixarivan_company_profile_load()['company_logo'] ?? ''));
    if ($rel === '') {
        return '';
    }
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    if (str_contains($rel, '..')) {
        return '';
    }
    $abs = fixarivan_company_site_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    return is_readable($abs) ? $rel : '';
}

/** URL для <img> с cache-bust по mtime. */
function fixarivan_brand_logo_url(): string {
    $rel = fixarivan_brand_logo_rel_path();
    if ($rel === '') {
        return '';
    }
    $abs = fixarivan_company_site_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $v = @filemtime($abs);
    return $rel . ($v ? ('?v=' . $v) : '');
}
