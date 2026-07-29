<?php

function logsStoragePath(): string
{
    return __DIR__ . '/logs.data.json';
}

function saveLogs(array $logs): void
{
    $storage = logsStoragePath();
    $json = json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Failed to encode logs JSON');
    }
    if (file_put_contents($storage, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Failed to write logs storage');
    }
}

function loadLogs(): array
{
    $storage = logsStoragePath();
    if (!is_file($storage)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($storage), true);
    return is_array($decoded) ? $decoded : [];
}

function saveLog(string $istId, string $action, array $details = []): void
{
    $logs = loadLogs();

    $logs[] = [
        'timestamp' => time(),
        'istId' => $istId,
        'action' => $action,
        'details' => $details,
    ];

    saveLogs($logs);
}
