<?php
ini_set('display_errors', 1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// Use a dedicated session name to avoid collisions with other PHP apps on the same domain.
// Shared between /admin and /team so users only login once.
// Must be set before session_start().
session_name('NEBSESSID');

// Make the session cookie path prefix-aware (important on hosts like /~istXXXX/).
$__scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$__pos = strpos($__scriptName, '/private/admin/');
if ($__pos === false) {
    $__pos = strpos($__scriptName, '/admin/');
}
$__basePath = ($__pos !== false) ? substr($__scriptName, 0, $__pos) : '';
$__cookiePath = ($__basePath !== '' ? $__basePath . '/' : '/');
ini_set('session.cookie_path', $__cookiePath);

ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);

require_once(__DIR__ . '/../../fenixedu-sdk/FenixEdu.class.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Polyfills for older PHP runtimes (many shared hosts still run PHP 7.x)
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        if ($needle === '') return true;
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        if ($needle === '') return true;
        $len = strlen($needle);
        if ($len === 0) return true;
        if ($len > strlen($haystack)) return false;
        return substr($haystack, -$len) === $needle;
    }
}

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        if ($needle === '') return true;
        return strpos($haystack, $needle) !== false;
    }
}

function adminBasePath(): string
{
    // Computed once above, before session_start().
    return (string)($GLOBALS['__basePath'] ?? '');
}

function canonicalPrivatePath(string $path): string
{
    // Keep root-relative
    $path = '/' . ltrim($path, '/');

    // Already canonical
    if (strncmp($path, '/private/', 9) === 0) {
        return $path;
    }

    // Rewrite legacy/public routes into /private
    if (strncmp($path, '/admin/', 7) === 0) {
        return '/private' . $path;
    }
    if (strncmp($path, '/team/', 6) === 0) {
        return '/private' . $path;
    }

    return $path;
}

function adminUrl(string $path): string
{
    return siteUrl($path);
}

function siteUrl(string $path): string
{
    // $path should be site-root relative like '/admin/login.php' or '/private/admin/login.php'
    $base = adminBasePath();
    $path = canonicalPrivatePath($path);
    return $base . $path;
}
