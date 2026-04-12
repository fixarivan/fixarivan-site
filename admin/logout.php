<?php
session_start([
    'cookie_path' => '/',
    'cookie_samesite' => 'Lax',
    'cookie_httponly' => true,
]);

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], !empty($p['secure']), $p['httponly']);
}

session_destroy();
header('Location: login.php');
exit();
