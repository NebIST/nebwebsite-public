<?php
declare(strict_types=1);

require_once(__DIR__ . '/bootstrap.php');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function isTeamLoggedIn(): bool
{
    return isset($_SESSION['user']) && is_array($_SESSION['user']) && !empty($_SESSION['user']['istid']);
}

function requireTeamLogin(): void
{
    if (!isTeamLoggedIn()) {
        header('Location: ' . siteUrl('/private/oauth-start.php?next=/private/team/index.php'));
        exit;
    }
}

function ensureTeamCsrf(): string
{
    if (empty($_SESSION['team_csrf']) || !is_string($_SESSION['team_csrf'])) {
        $_SESSION['team_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['team_csrf'];
}

function verifyTeamCsrf(string $token): void
{
    $expected = (string)($_SESSION['team_csrf'] ?? '');
    if ($expected === '' || !hash_equals($expected, $token)) {
        throw new RuntimeException('Invalid CSRF token. Please refresh and retry.');
    }
}
