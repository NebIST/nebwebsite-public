<?php
declare(strict_types=1);

require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/private/roles.php');

// Prevent caching of protected content
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$istid = (string)($_SESSION['user']['istid'] ?? '');
if ($istid === '') {
    header('Location: ' . siteUrl('/private/oauth-start.php?next=/private/admin/index.php'));
    exit;
}

if (!hasAnyRole($istid)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Not authorized</title>';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:40px;max-width:720px}a{font-size:16px}</style>';
    echo '</head><body>';
    echo '<h1>Not authorized</h1>';
    echo '<p>You are logged in, but you do not have access to the admin area.</p>';
    echo '<p><a href="' . htmlspecialchars(siteUrl('/private/index.php'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Voltar ao portal</a></p>';
    echo '</body></html>';
    exit;
}
