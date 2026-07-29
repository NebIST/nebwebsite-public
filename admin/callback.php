<?php
declare(strict_types=1);

// Fénix callback URL is configured here; handle centrally.
$qs = (string)($_SERVER['QUERY_STRING'] ?? '');
$target = '/private/callback.php' . ($qs !== '' ? ('?' . $qs) : '');
header('Location: ' . $target);
exit;
