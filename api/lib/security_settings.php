<?php
declare(strict_types=1);

function fixarivan_security_settings_path(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'security_settings.json';
}

/**
 * @return array<string,mixed>
 */
function fixarivan_security_settings_load(): array
{
    $defaults = [
        'delete_password_hash' => password_hash('1989', PASSWORD_DEFAULT),
    ];
    $path = fixarivan_security_settings_path();
    if (!is_file($path)) {
        return $defaults;
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return $defaults;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }
    return array_merge($defaults, $decoded);
}

function fixarivan_security_settings_save(array $settings): void
{
    $current = fixarivan_security_settings_load();
    $merged = array_merge($current, $settings);
    $path = fixarivan_security_settings_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create storage directory');
    }
    $encoded = json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($encoded === false) {
        throw new RuntimeException('Cannot encode security settings JSON');
    }
    if (file_put_contents($path, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Cannot write security settings');
    }
}

function fixarivan_set_delete_password(string $plainPassword): void
{
    $plainPassword = trim($plainPassword);
    if ($plainPassword === '') {
        throw new InvalidArgumentException('Delete password cannot be empty');
    }
    if (strlen($plainPassword) < 4) {
        throw new InvalidArgumentException('Delete password must be at least 4 characters');
    }
    fixarivan_security_settings_save([
        'delete_password_hash' => password_hash($plainPassword, PASSWORD_DEFAULT),
    ]);
}

function fixarivan_verify_delete_password(string $plainPassword): bool
{
    $plainPassword = trim($plainPassword);
    if ($plainPassword === '') {
        return false;
    }
    $settings = fixarivan_security_settings_load();
    $hash = (string)($settings['delete_password_hash'] ?? '');
    if ($hash === '') {
        return hash_equals('1989', $plainPassword);
    }
    return password_verify($plainPassword, $hash);
}
