<?php
declare(strict_types=1);

function teamsRoot(): string
{
    // Store teams under site-root /data/teams (same pattern as activities/books).
    // __DIR__ is .../private/admin/private, so dirname(__DIR__, 3) is repo root.
    return rtrim(dirname(__DIR__, 3), '/') . '/data/teams';
}

function ensureDir(string $dir): void
{
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create directory: ' . $dir);
        }
    }
}

function isValidDeptSlug(string $slug): bool
{
    // allow nested like "presidency/direcao"
    if ($slug === '') return false;
    if (str_contains($slug, '..')) return false;
    return (bool)preg_match('~^[a-z0-9][a-z0-9\-]*(/[a-z0-9][a-z0-9\-]*)*$~', $slug);
}

function deptDir(string $slug): string
{
    if (!isValidDeptSlug($slug)) {
        throw new InvalidArgumentException('Invalid department slug.');
    }
    return rtrim(teamsRoot(), '/') . '/' . $slug;
}

function deptJsonPath(string $slug): string
{
    return deptDir($slug) . '/department.json';
}

function deptPeopleDir(string $slug): string
{
    return deptDir($slug) . '/people';
}

function deptPhotosDir(string $slug): string
{
    return deptDir($slug) . '/photos';
}

function deptPeoplePhotosDir(string $slug): string
{
    return deptPeopleDir($slug) . '/photos';
}

function deptPeoplePath(string $slug, string $istid): string
{
    $istid = strtolower(trim($istid));
    if (!preg_match('/^[a-z0-9_\-]{3,64}$/', $istid)) {
        throw new InvalidArgumentException('Invalid istid.');
    }
    return deptPeopleDir($slug) . '/' . $istid . '.json';
}

function loadJsonFile(string $path): array
{
    if (!is_file($path)) return [];
    $raw = file_get_contents($path);
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : [];
}

function saveJsonFile(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) throw new RuntimeException('Failed to encode JSON.');
    if (file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        $err = error_get_last()['message'] ?? 'unknown error';
        throw new RuntimeException('Failed to write JSON: ' . $err);
    }
}

function slugify(string $name): string
{
    $name = trim(mb_strtolower($name));
    $name = preg_replace('/[^\p{L}\p{N}]+/u', '-', $name) ?? '';
    $name = trim($name, '-');
    $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
    $name = preg_replace('/[^a-z0-9\-]+/', '', $name) ?? '';
    $name = preg_replace('/\-+/', '-', $name) ?? '';
    return $name ?: 'dept';
}

function ensureMandatoryOrgaosSociais(): void
{
    ensureDir(teamsRoot());

    $base = deptDir('presidency');
    ensureDir($base);

    $mandatory = [
        'presidency/direcao' => 'Direção',
        'presidency/assembleia-geral' => 'Assembleia Geral',
        'presidency/conselho-fiscal' => 'Conselho Fiscal',
    ];

    foreach ($mandatory as $slug => $label) {
        ensureDir(deptDir($slug));
        ensureDir(deptPeopleDir($slug));
        ensureDir(deptPhotosDir($slug));
        ensureDir(deptPeoplePhotosDir($slug));

        $p = deptJsonPath($slug);
        if (!is_file($p)) {
            $now = time();
            saveJsonFile($p, [
                'slug' => $slug,
                'name' => $label,
                'description' => '',
                'photo' => '',
                'presidentMessage' => ($slug === 'presidency/direcao') ? '' : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}

function listDepartments(): array
{
    ensureDir(teamsRoot());

    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(teamsRoot(), FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $fi) {
        /** @var SplFileInfo $fi */
        if (!$fi->isDir()) continue;

        $json = $fi->getPathname() . '/department.json';
        if (!is_file($json)) continue;

        $rel = substr($fi->getPathname(), strlen(rtrim(teamsRoot(), '/')) + 1);
        $rel = str_replace('\\', '/', $rel);
        if (!isValidDeptSlug($rel)) continue;

        $d = loadJsonFile($json);
        $d['slug'] = $rel;
        $out[] = $d;
    }

    usort($out, function ($a, $b) {
        return strcmp((string)($a['slug'] ?? ''), (string)($b['slug'] ?? ''));
    });
    return $out;
}

function teamsMembershipIndexPath(): string
{
    return rtrim(teamsRoot(), '/') . '/membership-index.json';
}

function loadMembershipIndex(): array
{
    $path = teamsMembershipIndexPath();
    if (!is_file($path)) {
        return rebuildMembershipIndex();
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        return rebuildMembershipIndex();
    }

    $out = [];
    foreach ($decoded as $istid => $value) {
        if (!is_string($istid) || $istid === '') {
            continue;
        }
        $out[strtolower(trim($istid))] = true;
    }

    return $out;
}

function saveMembershipIndex(array $index): void
{
    $path = teamsMembershipIndexPath();
    $normalized = [];
    foreach ($index as $istid => $flag) {
        if (!is_string($istid) || $istid === '') {
            continue;
        }
        $normalized[strtolower(trim($istid))] = true;
    }

    $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Failed to encode membership index.');
    }

    if (file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Failed to write membership index.');
    }
}

function rebuildMembershipIndex(): array
{
    ensureDir(teamsRoot());
    $index = [];

    foreach (listDepartments() as $d) {
        $slug = (string)($d['slug'] ?? '');
        if ($slug === '') {
            continue;
        }

        $peopleDir = deptPeopleDir($slug);
        if (!is_dir($peopleDir)) {
            continue;
        }

        foreach (glob($peopleDir . '/*.json') ?: [] as $file) {
            $row = loadJsonFile($file);
            $istid = strtolower(trim((string)($row['istid'] ?? '')));
            if ($istid !== '') {
                $index[$istid] = true;
            }
        }
    }

    saveMembershipIndex($index);
    return $index;
}

function isUserInAnyTeam(string $istid): bool
{
    $istid = strtolower(trim($istid));
    if ($istid === '') {
        return false;
    }

    return isset(loadMembershipIndex()[$istid]);
}

function addUserToDepartment(string $deptSlug, array $user): void
{
    $deptSlug = (string)$deptSlug;
    $istid = strtolower(trim((string)($user['istid'] ?? '')));
    $name = (string)($user['name'] ?? '');
    $email = (string)($user['email'] ?? '');

    if ($istid === '' || $name === '') {
        throw new InvalidArgumentException('Invalid user.');
    }

    ensureDir(deptDir($deptSlug));
    ensureDir(deptPeopleDir($deptSlug));
    ensureDir(deptPeoplePhotosDir($deptSlug));

    $p = deptPeoplePath($deptSlug, $istid);
    if (is_file($p)) return;

    $now = time();
    saveJsonFile($p, [
        'istid' => $istid,
        'name' => $name,
        'email' => $email,
        'photo' => '',
        'linkedinUrl' => '',
        'cvUrl' => '',
        'role' => '',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    rebuildMembershipIndex();
}

function removeUserFromDepartment(string $deptSlug, string $istid): void
{
    $istid = strtolower(trim($istid));
    $p = deptPeoplePath($deptSlug, $istid);

    if (is_file($p)) {
        @unlink($p);
    }

    rebuildMembershipIndex();

    // also delete photo best-effort
    $photosDir = deptPeoplePhotosDir($deptSlug);
    if (is_dir($photosDir)) {
        foreach (glob($photosDir . '/' . $istid . '.*') ?: [] as $f) {
            @unlink($f);
        }
    }
}

function updateUserInDepartment(string $deptSlug, string $istid, array $patch): void
{
    $p = deptPeoplePath($deptSlug, $istid);
    if (!is_file($p)) throw new RuntimeException('Not a member of this department.');

    $cur = loadJsonFile($p);
    $next = array_merge($cur, $patch);
    $next['updated_at'] = time();
    saveJsonFile($p, $next);
}

function setDepartmentMeta(string $deptSlug, string $name, string $desc, string $photo): void
{
    if (mb_strlen($desc) > 512) {
        throw new InvalidArgumentException('Department description too long (max 512).');
    }

    $p = deptJsonPath($deptSlug);
    $cur = loadJsonFile($p);
    $cur['slug'] = $deptSlug;
    $cur['name'] = $name;
    $cur['description'] = $desc;
    $cur['photo'] = $photo;
    $cur['updated_at'] = time();
    if (!isset($cur['created_at'])) $cur['created_at'] = time();
    saveJsonFile($p, $cur);
}

function deleteDepartment(string $deptSlug): void
{
    if (!isValidDeptSlug($deptSlug)) {
        throw new InvalidArgumentException('Invalid department slug.');
    }

    $root = rtrim(str_replace('\\', '/', teamsRoot()), '/');
    $dir = str_replace('\\', '/', deptDir($deptSlug));

    if (!is_dir($dir)) {
        return;
    }

    if (strncmp($dir, $root . '/', strlen($root) + 1) !== 0) {
        throw new RuntimeException('Refusing to delete outside teams root.');
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($it as $fi) {
        /** @var SplFileInfo $fi */
        $p = $fi->getPathname();
        if ($fi->isDir()) {
            if (!@rmdir($p) && is_dir($p)) {
                throw new RuntimeException('Failed to remove directory: ' . $p);
            }
        } else {
            if (!@unlink($p) && is_file($p)) {
                throw new RuntimeException('Failed to delete file: ' . $p);
            }
        }
    }

    if (!@rmdir($dir) && is_dir($dir)) {
        throw new RuntimeException('Failed to remove department directory: ' . $dir);
    }

    rebuildMembershipIndex();
}