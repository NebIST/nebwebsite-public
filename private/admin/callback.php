<?php
declare(strict_types=1);

// OAuth is centralized in /private/callback.php
$qs = (string)($_SERVER['QUERY_STRING'] ?? '');
$target = '/private/callback.php' . ($qs !== '' ? ('?' . $qs) : '');
header('Location: ' . $target);
exit;
