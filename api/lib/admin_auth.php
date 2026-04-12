<?php
declare(strict_types=1);

/**
 * Учётные данные админки: приоритет у storage/admin_auth.json (логин + bcrypt),
 * иначе — константы ADMIN_USERNAME / ADMIN_PASSWORD из config.local.php.
 */

function fixarivan_admin_auth_json_path(): string {
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'admin_auth.json';
}

/**
 * @return array{username:string,password_hash:string}|null
 */
function fixarivan_admin_load_file_auth(): ?array {
    $path = fixarivan_admin_auth_json_path();
    if (!is_readable($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    $user = trim((string) ($data['username'] ?? ''));
    $hash = (string) ($data['password_hash'] ?? '');
    if ($user === '' || $hash === '') {
        return null;
    }
    return ['username' => $user, 'password_hash' => $hash];
}

/**
 * Проверка логина/пароля (файл или config).
 */
function fixarivan_admin_verify(string $username, string $password): bool {
    $file = fixarivan_admin_load_file_auth();
    if ($file !== null) {
        return hash_equals($file['username'], $username) && password_verify($password, $file['password_hash']);
    }

    if (!defined('ADMIN_USERNAME') || !defined('ADMIN_PASSWORD')) {
        return false;
    }

    return hash_equals((string) ADMIN_USERNAME, $username) && hash_equals((string) ADMIN_PASSWORD, $password);
}

/**
 * Текущее имя пользователя для отображения (файл или config).
 */
function fixarivan_admin_effective_username(): string {
    $file = fixarivan_admin_load_file_auth();
    if ($file !== null) {
        return $file['username'];
    }
    return defined('ADMIN_USERNAME') ? (string) ADMIN_USERNAME : '';
}

/**
 * Сохранить новые учётные данные (только после проверки текущего пароля снаружи).
 */
function fixarivan_admin_save_credentials(string $newUsername, string $plainPassword): void {
    $newUsername = trim($newUsername);
    if ($newUsername === '' || $plainPassword === '') {
        throw new InvalidArgumentException('Логин и пароль не могут быть пустыми');
    }

    $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
    if ($hash === false) {
        throw new RuntimeException('Не удалось сформировать хеш пароля');
    }

    $dir = dirname(fixarivan_admin_auth_json_path());
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Нет каталога storage/');
    }

    $path = fixarivan_admin_auth_json_path();
    $payload = json_encode(
        ['username' => $newUsername, 'password_hash' => $hash, 'updated_at' => date('c')],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($payload === false) {
        throw new RuntimeException('Ошибка сериализации');
    }

    if (file_put_contents($path, $payload, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать файл учётных данных');
    }
    @chmod($path, 0600);
}
