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
