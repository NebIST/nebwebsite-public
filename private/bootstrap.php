<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// Shared session for /admin + /team + /private.
// Must be set before session_start().
session_name('NEBSESSID');

// Prefix-aware cookie path (important on hosts like /~istXXXX/).
$__scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$__pos = strpos($__scriptName, '/private/');
$__basePath = ($__pos !== false) ? substr($__scriptName, 0, $__pos) : '';
$__cookiePath = ($__basePath !== '' ? $__basePath . '/' : '/');
ini_set('session.cookie_path', $__cookiePath);

ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');

require_once(__DIR__ . '/../fenixedu-sdk/FenixEdu.class.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function privateBasePath(): string
{
    return (string)($GLOBALS['__basePath'] ?? '');
}

function siteUrl(string $path): string
{
    $base = privateBasePath();
    $path = '/' . ltrim($path, '/');
    return $base . $path;
}
