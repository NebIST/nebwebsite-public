<?php

require_once(__DIR__ . '/logs.php');

function userSchemaPath(): string
{
    return __DIR__ . '/user.store.json';
}

function userDataStoragePath(): string
{
    return __DIR__ . '/user.data.json';
}

function loadUserSchema(): array
{
    $schemaPath = userSchemaPath();
    if (!is_file($schemaPath)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($schemaPath), true);
    return is_array($decoded) ? $decoded : [];
}

function isUserSaved(string $username): bool
{
    $username = normalizeIstId($username);
    if ($username === '' || !isValidIstId($username)) {
        return false;
    }

    $data = loadUserData();
    return isset($data[$username]) && is_array($data[$username]);
}

function normalizeIstId(string $username): string
{
    return strtolower(trim($username));
}

function isValidIstId(string $username): bool
{
    return (bool) preg_match('/^[a-z0-9_\-]{3,64}$/', $username);
}

function loadUserData(): array
{
    $storage = userDataStoragePath();
    if (!is_file($storage)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($storage), true);
    return is_array($decoded) ? $decoded : [];
}

function saveUserData(array $data): void
{
    $storage = userDataStoragePath();
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Failed to encode user data JSON');
    }
    if (file_put_contents($storage, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Failed to write user data storage');
    }
    saveLog("system", "user_data_saved", ['user_count' => count($data)]);
}

function validateUserAgainstSchema(array $user): void
{
    $schema = loadUserSchema();
    $groups = $schema['groups'] ?? [];
    if (!is_array($groups)) {
        return;
    }

    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }
        $fields = $group['fields'] ?? [];
        if (!is_array($fields)) {
            continue;
        }
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $fieldId = $field['id'] ?? null;
            $type = $field['type'] ?? 'string';
            $required = (bool)($field['required'] ?? false);
            if (!is_string($fieldId) || $fieldId === '') {
                continue;
            }

            $value = $user[$fieldId] ?? null;
            if ($required && (!is_string($value) || trim($value) === '')) {
                throw new InvalidArgumentException('Missing required field: ' . $fieldId);
            }

            if ($value === null || $value === '') {
                continue;
            }
            if (!is_string($value)) {
                throw new InvalidArgumentException('Invalid field type for: ' . $fieldId);
            }

            if ($type === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Invalid email');
            }
        }
    }
}

function getUser(string $username): ?array
{
    $username = normalizeIstId($username);
    if ($username === '' || !isValidIstId($username)) {
        return null;
    }

    $data = loadUserData();
    if (isset($data[$username]) && is_array($data[$username])) {
        return $data[$username];
    }
    return null;
}

function saveUser(string $username, array $user): bool
{
    $username = normalizeIstId($username);
    if ($username === '' || !isValidIstId($username)) {
        throw new InvalidArgumentException('Invalid IST ID');
    }

    $alreadyExists = isUserSaved($username);

    // Ensure key + field are consistent
    $user['istid'] = $username;

    validateUserAgainstSchema($user);

    $data = loadUserData();
    $data[$username] = $user;
    saveUserData($data);
    return !$alreadyExists;
}