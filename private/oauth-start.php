<?php
declare(strict_types=1);

require_once(__DIR__ . '/bootstrap.php');

$next = (string)($_GET['next'] ?? '');
if ($next === '' || $next[0] !== '/') {
    $next = '/private/index.php';
}

// If already logged in, go to portal.
if (!empty($_SESSION['user']['istid'])) {
    header('Location: ' . siteUrl('/private/index.php'));
    exit;
}

// Clean any prior session state before starting OAuth
$_SESSION = [];

if (session_status() === PHP_SESSION_ACTIVE) {
    session_regenerate_id(true);
}

// After callback, come back to the portal.
$_SESSION['post_login_redirect'] = $next;

$fenix = FenixEdu::getSingleton();
$authUrl = $fenix->getAuthUrl();

header('Location: ' . $authUrl);
exit;
