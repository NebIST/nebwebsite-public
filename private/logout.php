<?php
declare(strict_types=1);

require_once(__DIR__ . '/bootstrap.php');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$_SESSION = [];

unset($_SESSION['accessToken'], $_SESSION['refreshToken'], $_SESSION['expires']);

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    $pathsToClear = array_unique([
        $params['path'] ?? '/',
        '/',
    ]);

    foreach ($pathsToClear as $path) {
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $path,
            $params['domain'] ?? '',
            (bool)($params['secure'] ?? false),
            (bool)($params['httponly'] ?? true)
        );
    }
}

session_destroy();

if (session_status() !== PHP_SESSION_NONE) {
    session_write_close();
}

header('Location: ' . siteUrl('/private/index.php'));
exit;
