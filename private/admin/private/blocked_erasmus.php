<?php
declare(strict_types=1);

require_once(__DIR__ . '/erasmus_store.php');

function erasmusBlockedUsersPath(): string
{
    return __DIR__ . '/blocked_erasmus.json';
}

function erasmusDefaultBlockedUsers(): array
{
    return [];
}

function erasmusEnsureBlockedUsersStorage(): void
{
    $path = erasmusBlockedUsersPath();
    if (is_file($path)) {
        return;
    }

    if (file_put_contents($path, "[]\n", LOCK_EX) === false) {
        throw new RuntimeException('Failed to create blocked Erasmus users file.');
    }
}

function erasmusLoadBlockedUsers(): array
{
    erasmusEnsureBlockedUsersStorage();

    $path = erasmusBlockedUsersPath();
    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        return erasmusDefaultBlockedUsers();
    }

    $users = [];
    foreach ($decoded as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $istid = erasmusNormalizeIstid((string)($entry['istid'] ?? ''));
        if ($istid === '') {
            continue;
        }

        $users[] = [
            'istid' => $istid,
            'reason' => trim((string)($entry['reason'] ?? '')),
            'added_at' => (int)($entry['added_at'] ?? 0),
        ];
    }

    return $users;
}

function erasmusSaveBlockedUsers(array $users): void
{
    $path = erasmusBlockedUsersPath();
    $normalized = [];

    foreach ($users as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $istid = erasmusNormalizeIstid((string)($entry['istid'] ?? ''));
        if ($istid === '') {
            continue;
        }

        $normalized[] = [
            'istid' => $istid,
            'reason' => trim((string)($entry['reason'] ?? '')),
            'added_at' => (int)($entry['added_at'] ?? 0),
        ];
    }

    $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('Failed to encode blocked Erasmus users JSON.');
    }

    if (file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Failed to persist blocked Erasmus users.');
    }
}

function erasmusIsBlockedUser(string $istid): bool
{
    $istid = erasmusNormalizeIstid($istid);
    if ($istid === '') {
        return false;
    }

    foreach (erasmusLoadBlockedUsers() as $entry) {
        if ((string)($entry['istid'] ?? '') === $istid) {
            return true;
        }
    }

    return false;
}

function erasmusAddBlockedUser(string $istid, string $reason = ''): array
{
    $istid = erasmusNormalizeIstid($istid);
    if ($istid === '') {
        throw new InvalidArgumentException('IST ID is required.');
    }

    $users = erasmusLoadBlockedUsers();
    foreach ($users as $index => $entry) {
        if ((string)($entry['istid'] ?? '') === $istid) {
            $users[$index]['reason'] = trim($reason);
            $users[$index]['added_at'] = (int)($users[$index]['added_at'] ?? time());
            erasmusSaveBlockedUsers($users);
            return $users[$index];
        }
    }

    $users[] = [
        'istid' => $istid,
        'reason' => trim($reason),
        'added_at' => time(),
    ];

    erasmusSaveBlockedUsers($users);
    return $users[count($users) - 1];
}

function erasmusRemoveBlockedUser(string $istid): bool
{
    $istid = erasmusNormalizeIstid($istid);
    if ($istid === '') {
        return false;
    }

    $users = erasmusLoadBlockedUsers();
    $filtered = [];
    $removed = false;

    foreach ($users as $entry) {
        if ((string)($entry['istid'] ?? '') === $istid) {
            $removed = true;
            continue;
        }
        $filtered[] = $entry;
    }

    if ($removed) {
        erasmusSaveBlockedUsers($filtered);
    }

    return $removed;
}
