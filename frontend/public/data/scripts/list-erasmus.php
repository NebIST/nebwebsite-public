<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        if ($needle === '') return true;
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

try {
    $candidates = [];
    $c1 = realpath(__DIR__ . '/../../');
    if (is_string($c1) && $c1 !== '') $candidates[] = $c1;

    $c2 = realpath(__DIR__ . '/../../../../');
    if (is_string($c2) && $c2 !== '') $candidates[] = $c2;

    $dataRoot = '';
    foreach ($candidates as $cand) {
        if (is_file($cand . '/data/erasmus/erasmus.info.json')) {
            $dataRoot = $cand . '/data';
            break;
        }
        if (is_file($cand . '/erasmus/erasmus.info.json')) {
            $dataRoot = $cand;
            break;
        }
    }

    if ($dataRoot === '') {
        echo json_encode([
            'ok' => true,
            'countries' => [],
            'generatedAt' => time(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $jsonPath = $dataRoot . '/erasmus/erasmus.info.json';
    if (!is_file($jsonPath)) {
        echo json_encode([
            'ok' => true,
            'countries' => [],
            'generatedAt' => time(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $store = json_decode((string)file_get_contents($jsonPath), true);
    if (!is_array($store)) {
        throw new RuntimeException('Invalid Erasmus store JSON.');
    }

    $universities = $store['universities'] ?? [];
    $stories = $store['stories'] ?? [];
    if (!is_array($universities)) $universities = [];
    if (!is_array($stories)) $stories = [];

    $uniById = [];
    foreach ($universities as $u) {
        if (!is_array($u)) continue;
        $id = trim((string)($u['id'] ?? ''));
        $name = trim((string)($u['name'] ?? ''));
        $country = trim((string)($u['country'] ?? ''));
        if ($id === '' || $name === '' || $country === '') continue;

        $photo = trim((string)($u['photo'] ?? ''));
        $uniById[$id] = [
            'id' => $id,
            'name' => $name,
            'country' => $country,
            'photoUrl' => $photo !== '' ? ('/data/erasmus/university_photos/' . rawurlencode($photo)) : '',
        ];
    }

    $grouped = [];

    foreach ($stories as $s) {
        if (!is_array($s)) continue;

        $status = trim(strtolower((string)($s['status'] ?? 'pending')));
        if ($status !== 'approved') continue;

        $uniId = trim((string)($s['university_id'] ?? ''));
        if ($uniId === '' || !isset($uniById[$uniId])) continue;

        $uni = $uniById[$uniId];
        $country = (string)$uni['country'];

        if (!isset($grouped[$country])) {
            $grouped[$country] = [];
        }

        if (!isset($grouped[$country][$uniId])) {
            $grouped[$country][$uniId] = [
                'id' => $uniId,
                'name' => (string)$uni['name'],
                'country' => $country,
                'photoUrl' => (string)$uni['photoUrl'],
                'stories' => [],
            ];
        }

        $studentName = trim((string)($s['student_name'] ?? ''));
        $studentPhoto = trim((string)($s['student_photo'] ?? ''));
        $storyText = trim((string)($s['story_text'] ?? ''));
        $summary = trim((string)($s['story_summary'] ?? ''));

        if ($studentName === '' || $storyText === '' || $studentPhoto === '') {
            continue;
        }

        $grouped[$country][$uniId]['stories'][] = [
            'id' => trim((string)($s['id'] ?? '')),
            'studentName' => $studentName,
            'studentPhotoUrl' => '/data/erasmus/student_photos/' . rawurlencode($studentPhoto),
            'story' => $storyText,
            'summary' => $summary,
            'updatedAt' => (int)($s['updated_at'] ?? 0),
        ];
    }

    $countriesOut = [];

    foreach ($grouped as $country => $unisById) {
        $unis = array_values($unisById);

        usort($unis, static function ($a, $b): int {
            $an = (string)($a['name'] ?? '');
            $bn = (string)($b['name'] ?? '');
            return strcasecmp($an, $bn);
        });

        foreach ($unis as &$uni) {
            $storiesList = is_array($uni['stories'] ?? null) ? $uni['stories'] : [];
            usort($storiesList, static function ($a, $b): int {
                $aa = (string)($a['studentName'] ?? '');
                $bb = (string)($b['studentName'] ?? '');
                return strcasecmp($aa, $bb);
            });
            $uni['stories'] = $storiesList;
        }
        unset($uni);

        $countriesOut[] = [
            'name' => (string)$country,
            'universities' => $unis,
        ];
    }

    usort($countriesOut, static function ($a, $b): int {
        $an = (string)($a['name'] ?? '');
        $bn = (string)($b['name'] ?? '');
        return strcasecmp($an, $bn);
    });

    echo json_encode([
        'ok' => true,
        'countries' => $countriesOut,
        'generatedAt' => time(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('list-erasmus.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    echo json_encode([
        'ok' => false,
        'countries' => [],
        'error' => 'internal_error',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} finally {
    restore_error_handler();
}
