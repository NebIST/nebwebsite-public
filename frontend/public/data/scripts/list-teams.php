<?php
declare(strict_types=1);

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
        $n = strlen($needle);
        if ($n === 0) return true;
        return substr($haystack, -$n) === $needle;
    }
}

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        if ($needle === '') return true;
        return strpos($haystack, $needle) !== false;
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    /**
     * Find the site root in both environments:
     * - Local dev: .../nebwebsite/frontend/public/data/scripts
     * - Deployed export: .../web/data/scripts
     */
    $candidateRoots = [];
    $c1 = realpath(__DIR__ . '/../../');
    if (is_string($c1) && $c1 !== '') $candidateRoots[] = $c1;
    $c2 = realpath(__DIR__ . '/../../../../');
    if (is_string($c2) && $c2 !== '') $candidateRoots[] = $c2;

    $root = '';
    foreach ($candidateRoots as $cand) {
        // We consider it a root if it has /data. /data/teams may not exist yet.
        if (is_dir($cand . '/data')) {
            $root = $cand;
            // Prefer the root that already has /data/teams.
            if (is_dir($cand . '/data/teams')) break;
        }
    }

    if ($root === '') {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'root_not_found'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $teamsRoot = rtrim($root, '/') . '/data/teams';

    function loadJsonFile(string $path): array
    {
        if (!is_file($path)) return [];
        $raw = file_get_contents($path);
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    function listDepartmentSlugs(string $teamsRoot): array
    {
        $slugs = [];
        if (!is_dir($teamsRoot)) return $slugs;

        $ignore = ['.', '..', 'people', 'photos'];
        $stack = [[$teamsRoot, '']];

        while ($stack) {
            [$dir, $prefix] = array_pop($stack);
            $entries = scandir($dir);
            if (!is_array($entries)) continue;

            foreach ($entries as $name) {
                if (in_array($name, $ignore, true)) continue;
                $full = $dir . '/' . $name;
                if (!is_dir($full)) continue;

                $slug = $prefix === '' ? $name : ($prefix . '/' . $name);

                if (is_file($full . '/department.json')) {
                    $slugs[] = $slug;
                }

                // Always traverse: some parents (e.g. "presidency") are not a department.
                $stack[] = [$full, $slug];
            }
        }

        sort($slugs);
        return $slugs;
    }

    function deptDir(string $teamsRoot, string $slug): string
    {
        return rtrim($teamsRoot, '/') . '/' . $slug;
    }

    function deptJsonPath(string $teamsRoot, string $slug): string
    {
        return deptDir($teamsRoot, $slug) . '/department.json';
    }

    function deptPeopleDir(string $teamsRoot, string $slug): string
    {
        return deptDir($teamsRoot, $slug) . '/people';
    }

    function deptPeoplePublic(string $teamsRoot, string $deptSlug): array
    {
        $out = [];
        $pdir = deptPeopleDir($teamsRoot, $deptSlug);
        if (!is_dir($pdir)) return $out;

        foreach (glob($pdir . '/*.json') ?: [] as $pf) {
            $row = loadJsonFile($pf);
            if (!is_array($row)) continue;

            $istid = (string)($row['istid'] ?? '');
            if ($istid === '') continue;

            $photo = (string)($row['photo'] ?? '');

            $out[] = [
                'istid' => $istid,
                'name' => (string)($row['name'] ?? ''),
                'email' => (string)($row['email'] ?? ''),
                'role' => (string)($row['role'] ?? ''),
                'linkedinUrl' => (string)($row['linkedinUrl'] ?? ''),
                'cvUrl' => (string)($row['cvUrl'] ?? ''),
                'photoUrl' => $photo !== '' ? ('/data/teams/' . $deptSlug . '/' . $photo) : '',
            ];
        }

        usort($out, function ($a, $b) {
            return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });
        return $out;
    }

    function organsComplete(array $departments): bool
    {
        $bySlug = [];
        foreach ($departments as $d) {
            $bySlug[(string)($d['slug'] ?? '')] = $d;
        }

        $required = [
            'presidency/direcao' => [
                ['role' => 'Presidente', 'min' => 1, 'max' => 1],
                ['role' => 'Tesoureiro', 'min' => 1, 'max' => 1],
                ['role' => 'Secretário', 'min' => 1, 'max' => 1],
                ['role' => 'Vogal', 'min' => 1, 'max' => 9999],
            ],
            'presidency/assembleia-geral' => [
                ['role' => 'Presidente da Mesa', 'min' => 1, 'max' => 1],
                ['role' => 'Suplente da Mesa', 'min' => 1, 'max' => 1],
                ['role' => '1º Secretário', 'min' => 1, 'max' => 1],
                ['role' => '2º Secretário', 'min' => 1, 'max' => 1],
                ['role' => 'Vogal', 'min' => 1, 'max' => 9999],
            ],
            'presidency/conselho-fiscal' => [
                ['role' => 'Efetiva', 'min' => 1, 'max' => 9999],
            ],
        ];

        foreach ($required as $slug => $rules) {
            $dept = $bySlug[$slug] ?? null;
            if (!is_array($dept)) return false;
            $people = is_array($dept['people'] ?? null) ? $dept['people'] : [];
            if (count($people) === 0) return false;

            $counts = [];
            foreach ($people as $p) {
                $r = (string)($p['role'] ?? '');
                if ($r === '') continue;
                $counts[$r] = ($counts[$r] ?? 0) + 1;
            }

            foreach ($rules as $rule) {
                $r = (string)$rule['role'];
                $c = (int)($counts[$r] ?? 0);
                if ($c < (int)$rule['min']) return false;
                if ($c > (int)$rule['max']) return false;
            }
        }

        return true;
    }

    $departments = [];
    foreach (listDepartmentSlugs($teamsRoot) as $slug) {
        if ($slug === '') continue;

        $dept = loadJsonFile(deptJsonPath($teamsRoot, $slug));
        $photo = (string)($dept['photo'] ?? '');

        $departments[] = [
            'slug' => $slug,
            'name' => (string)($dept['name'] ?? $slug),
            'description' => (string)($dept['description'] ?? ''),
            'photoUrl' => $photo !== '' ? ('/data/teams/' . $slug . '/photos/' . rawurlencode($photo)) : '',
            'presidentMessage' => is_string($dept['presidentMessage'] ?? null) ? (string)$dept['presidentMessage'] : '',
            'people' => deptPeoplePublic($teamsRoot, $slug),
        ];
    }

    usort($departments, function ($a, $b) {
        return strcmp((string)($a['slug'] ?? ''), (string)($b['slug'] ?? ''));
    });

    echo json_encode([
        'ok' => true,
        'organsComplete' => organsComplete($departments),
        'departments' => $departments,
    ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    error_log('list-teams.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    echo json_encode([
        'ok' => false,
        'organsComplete' => false,
        'departments' => [],
        'error' => 'internal_error',
    ], JSON_UNESCAPED_SLASHES);
} finally {
    restore_error_handler();
}
