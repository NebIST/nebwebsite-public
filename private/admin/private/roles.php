<?php

function getDefinedRoles(): array
{
    return [
        'admin' => 'Admin',
        'site' => 'Gestor do Site',
        'letter' => 'Editor de NebLetter',
        'activity' => 'Gestor de Atividades',
        'books' => 'Gestor de Livros e Sebentas',
        'erasmus' => 'Gestor de Erasmus',
        'merch' => 'Gestor de Merch',
    ];
}

function rolesStoragePath(): string
{
    return __DIR__ . '/roles.store.json';
}
function userInfoStoragePath(): string
{
    return __DIR__ . '/user.store.json';
}

function normalizeUsername(string $username): string
{
    return strtolower(trim($username));
}

function isValidUsername(string $username): bool
{
    return (bool) preg_match('/^[a-z0-9_\-]{3,64}$/', $username);
}

function loadRoles(): array
{
    $roles = [];

    $storage = rolesStoragePath();
    if (is_file($storage)) {
        $decoded = json_decode((string) file_get_contents($storage), true);
        if (is_array($decoded)) {
            $roles = $decoded;
        }
    }

    // Defaults (keep an initial bootstrap admin list to avoid lockout)
    if (!isset($roles['admin']) || !is_array($roles['admin'])) {
        $roles['admin'] = [
            'ist1109643',
        ];
    }

    foreach (array_keys(getDefinedRoles()) as $roleKey) {
        if (!isset($roles[$roleKey]) || !is_array($roles[$roleKey])) {
            $roles[$roleKey] = [];
        }

        $normalized = [];
        foreach ($roles[$roleKey] as $username) {
            if (!is_string($username)) {
                continue;
            }
            $u = normalizeUsername($username);
            if ($u !== '' && isValidUsername($u)) {
                $normalized[$u] = true;
            }
        }
        $roles[$roleKey] = array_keys($normalized);
        sort($roles[$roleKey]);
    }

    // Remove unknown roles from persisted data
    $allowed = array_fill_keys(array_keys(getDefinedRoles()), true);
    foreach (array_keys($roles) as $roleKey) {
        if (!isset($allowed[$roleKey])) {
            unset($roles[$roleKey]);
        }
    }

    return $roles;
}

function saveRoles(array $roles): void
{
    $storage = rolesStoragePath();

    $json = json_encode($roles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Failed to encode roles JSON');
    }

    if (file_put_contents($storage, $json . "\n", LOCK_EX) === false) {
        $err = error_get_last()['message'] ?? 'unknown error';
        throw new RuntimeException('Failed to write roles storage: ' . $err);
    }
}

function isUserInRole(string $username, string $roleKey): bool
{
    $username = normalizeUsername($username);
    if ($username === '' || !isValidUsername($username)) {
        return false;
    }

    $roles = loadRoles();
    return in_array($username, $roles[$roleKey] ?? [], true);
}

function addUserToRole(string $username, string $roleKey): void
{
    $roleDefs = getDefinedRoles();
    if (!isset($roleDefs[$roleKey])) {
        throw new InvalidArgumentException('Unknown role');
    }

    $username = normalizeUsername($username);
    if ($username === '' || !isValidUsername($username)) {
        throw new InvalidArgumentException('Invalid username');
    }

    $roles = loadRoles();
    if (!in_array($username, $roles[$roleKey], true)) {
        $roles[$roleKey][] = $username;
        sort($roles[$roleKey]);
        saveRoles($roles);
    }
}

function removeUserFromRole(string $username, string $roleKey): void
{
    $roleDefs = getDefinedRoles();
    if (!isset($roleDefs[$roleKey])) {
        throw new InvalidArgumentException('Unknown role');
    }

    $username = normalizeUsername($username);
    if ($username === '' || !isValidUsername($username)) {
        throw new InvalidArgumentException('Invalid username');
    }

    $roles = loadRoles();
    $members = $roles[$roleKey] ?? [];

    if ($roleKey === 'admin') {
        $admins = array_values(array_unique($members));
        if (in_array($username, $admins, true) && count($admins) <= 1) {
            throw new RuntimeException('Cannot remove the last admin');
        }
    }

    $roles[$roleKey] = array_values(array_filter(
        $members,
        function ($u) use ($username) {
            return $u !== $username;
        }
    ));
    sort($roles[$roleKey]);
    saveRoles($roles);
}

function hasAnyRole(string $username): bool
{
    $username = normalizeUsername($username);
    if ($username === '' || !isValidUsername($username)) {
        return false;
    }

    $roles = loadRoles();
    foreach ($roles as $members) {
        if (in_array($username, $members, true)) {
            return true;
        }
    }
    return false;
}

function isAdmin(string $username): bool
{
    return isUserInRole($username, 'admin');
}

function isLetterEditor(string $username): bool
{
    return isUserInRole($username, 'letter');
}

function isActivityManager(string $username): bool
{
    return isUserInRole($username, 'activity');
}

function isBooksManager(string $username): bool
{
    return isUserInRole($username, 'books');
}

function isErasmusManager(string $username): bool
{
    return isUserInRole($username, 'erasmus');
}

function isMerchManager(string $username): bool
{
    return isUserInRole($username, 'merch');
}

function isSiteManager(string $username): bool
{
    return isUserInRole($username, 'site');
}

function retreiveUserRoles(string $username): array
{
    $username = normalizeUsername($username);
    if ($username === '' || !isValidUsername($username)) {
        return [];
    }

    $roles = loadRoles();
    $userRoles = [];
    foreach ($roles as $roleKey => $members) {
        if (in_array($username, $members, true)) {
            $userRoles[] = $roleKey;
        }
    }
    return $userRoles;
}

function retreiveUserFenixInfo(FenixEdu $fenix, string $username): ?object
{
    try {
        $person = $fenix->getPersonByUsername($username);
        return $person;
    } catch (FenixEduException $e) {
        return null;
    }
}