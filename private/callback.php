<?php
declare(strict_types=1);

require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/admin/private/user.php');
require_once(__DIR__ . '/admin/private/roles.php');

// Optional logging (do not fail login if missing)
$__logsPath = __DIR__ . '/admin/private/logs.php';
if (is_file($__logsPath)) {
    require_once($__logsPath);
}

function renderErrorPage(int $statusCode, string $title, string $message): void
{
    http_response_code($statusCode);
    header('Content-Type: text/html; charset=utf-8');

    $titleEsc = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $messageEsc = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    echo "<!doctype html>\n";
    echo "<html><head><meta charset=\"utf-8\"><title>{$titleEsc}</title>";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">";
    echo "<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:40px;max-width:720px}a{font-size:16px}</style>";
    echo "</head><body>";
    echo "<h1>{$titleEsc}</h1>";
    echo "<p>{$messageEsc}</p>";
    echo "<p><a href=\"" . htmlspecialchars(siteUrl('/private/index.php'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\">Voltar ao portal</a></p>";
    echo "</body></html>";
    exit;
}

if (isset($_GET['error'])) {
    renderErrorPage(401, 'Authorization denied', 'Fenix authorization was denied.');
}

if (empty($_GET['code'])) {
    renderErrorPage(400, 'Invalid callback', 'Missing authorization code.');
}

$fenix = FenixEdu::getSingleton();

try {
    $fenix->getAccessTokenFromCode($_GET['code']);
    $person = $fenix->getPerson();
    $istid = (string)($person->username ?? '');
} catch (FenixEduException $e) {
    renderErrorPage(500, 'Fenix error', 'Fenix API error: ' . $e->getErrorDescription());
} catch (Throwable $e) {
    renderErrorPage(500, 'Server error', 'Unexpected error: ' . $e->getMessage());
}

if ($istid === '') {
    renderErrorPage(502, 'Fenix error', 'Missing IST ID from Fenix.');
}

session_regenerate_id(true);

$_SESSION['user'] = [
    'name' => (string)($person->name ?? ''),
    'istid' => $istid,
    'email' => (string)($person->email ?? ''),
];

// Back-compat
$_SESSION['team_user'] = $_SESSION['user'];

// Persist user info for admin tooling (teams waiting list, etc.)
try {
    $created = saveUser($istid, [
        'istid' => $istid,
        'name' => (string)($person->name ?? ''),
        'email' => (string)($person->email ?? ''),
    ]);

    // If logs.php exists, record the event.
    if (function_exists('saveLog')) {
        $action = $created ? 'new_login_user_created' : 'user_login';
        $payload = [
            'istId' => $istid,
            'hasRoles' => hasAnyRole($istid),
        ];
        @saveLog('system', $action, $payload);
    }

    $_SESSION['flash_ok'] = $created ? 'Welcome! Your profile was saved.' : 'Welcome back!';
} catch (Throwable $e) {
    error_log('Failed to save user info for ' . $istid . ': ' . $e->getMessage());
    $_SESSION['flash_error'] = 'Logged in, but failed to save your profile.';
}

$postLogin = (string)($_SESSION['post_login_redirect'] ?? '');
unset($_SESSION['post_login_redirect']);

if ($postLogin !== '' && isset($postLogin[0]) && $postLogin[0] === '/') {
    header('Location: ' . siteUrl($postLogin));
    exit;
}

header('Location: ' . siteUrl('/private/index.php'));
exit;
