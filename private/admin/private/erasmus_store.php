<?php
declare(strict_types=1);

function erasmusStorageRoot(): string
{
    return dirname(__DIR__, 3) . '/data/erasmus';
}

function erasmusJsonPath(): string
{
    return erasmusStorageRoot() . '/erasmus.info.json';
}

function erasmusStudentPhotosDir(): string
{
    return erasmusStorageRoot() . '/student_photos';
}

function erasmusUniversityPhotosDir(): string
{
    return erasmusStorageRoot() . '/university_photos';
}

function erasmusDefaultStore(): array
{
    return [
        'version' => 1,
        'universities' => [],
        'stories' => [],
    ];
}

function erasmusEnsureStorage(): void
{
    $root = erasmusStorageRoot();
    $studentPhotos = erasmusStudentPhotosDir();
    $universityPhotos = erasmusUniversityPhotosDir();

    if (!is_dir($root) && !mkdir($root, 0755, true) && !is_dir($root)) {
        throw new RuntimeException('Failed to create Erasmus storage directory.');
    }

    if (!is_dir($studentPhotos) && !mkdir($studentPhotos, 0755, true) && !is_dir($studentPhotos)) {
        throw new RuntimeException('Failed to create Erasmus student photos directory.');
    }

    if (!is_dir($universityPhotos) && !mkdir($universityPhotos, 0755, true) && !is_dir($universityPhotos)) {
        throw new RuntimeException('Failed to create Erasmus university photos directory.');
    }

    $jsonPath = erasmusJsonPath();
    if (!is_file($jsonPath)) {
        erasmusSaveStore(erasmusDefaultStore());
    }
}

function erasmusLoadStore(): array
{
    $jsonPath = erasmusJsonPath();
    if (!is_file($jsonPath)) {
        return erasmusDefaultStore();
    }

    $decoded = json_decode((string)file_get_contents($jsonPath), true);
    if (!is_array($decoded)) {
        return erasmusDefaultStore();
    }

    $store = erasmusDefaultStore();

    $universities = $decoded['universities'] ?? [];
    if (!is_array($universities)) {
        $universities = [];
    }

    foreach ($universities as $u) {
        if (!is_array($u)) {
            continue;
        }

        $id = trim((string)($u['id'] ?? ''));
        $name = erasmusCleanText((string)($u['name'] ?? ''));
        $country = erasmusCleanText((string)($u['country'] ?? ''));
        if ($id === '' || $name === '' || $country === '') {
            continue;
        }

        $store['universities'][] = [
            'id' => $id,
            'name' => $name,
            'country' => $country,
            'photo' => trim((string)($u['photo'] ?? '')),
            'created_at' => (int)($u['created_at'] ?? 0),
            'updated_at' => (int)($u['updated_at'] ?? 0),
        ];
    }

    $stories = $decoded['stories'] ?? [];
    if (!is_array($stories)) {
        $stories = [];
    }

    foreach ($stories as $s) {
        if (!is_array($s)) {
            continue;
        }

        $id = trim((string)($s['id'] ?? ''));
        $istid = erasmusNormalizeIstid((string)($s['istid'] ?? ''));
        $universityId = trim((string)($s['university_id'] ?? ''));
        $studentName = erasmusCleanText((string)($s['student_name'] ?? ''));
        $storyText = trim((string)($s['story_text'] ?? ''));
        $studentPhoto = trim((string)($s['student_photo'] ?? ''));

        if ($id === '' || $istid === '' || $universityId === '' || $studentName === '' || $storyText === '' || $studentPhoto === '') {
            continue;
        }

        $status = erasmusNormalizeStatus((string)($s['status'] ?? 'pending'));

        $store['stories'][] = [
            'id' => $id,
            'istid' => $istid,
            'student_name' => $studentName,
            'student_email' => trim((string)($s['student_email'] ?? '')),
            'university_id' => $universityId,
            'story_text' => $storyText,
            'story_summary' => trim((string)($s['story_summary'] ?? '')),
            'student_photo' => $studentPhoto,
            'status' => $status,
            'admin_note' => trim((string)($s['admin_note'] ?? '')),
            'submitted_at' => (int)($s['submitted_at'] ?? 0),
            'updated_at' => (int)($s['updated_at'] ?? 0),
            'reviewed_at' => (int)($s['reviewed_at'] ?? 0),
            'reviewed_by' => trim((string)($s['reviewed_by'] ?? '')),
        ];
    }

    return $store;
}

function erasmusSaveStore(array $store): void
{
    $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('Failed to encode Erasmus JSON store.');
    }

    if (file_put_contents(erasmusJsonPath(), $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Failed to persist Erasmus JSON store.');
    }
}

function erasmusNormalizeIstid(string $istid): string
{
    return strtolower(trim($istid));
}

function erasmusNormalizeStatus(string $status): string
{
    $status = trim(strtolower($status));
    $allowed = ['pending', 'approved', 'changes_requested'];
    return in_array($status, $allowed, true) ? $status : 'pending';
}

function erasmusStatusLabel(string $status): string
{
    $status = erasmusNormalizeStatus($status);
    if ($status === 'approved') return 'Aprovada';
    if ($status === 'changes_requested') return 'Alterações solicitadas';
    return 'Pendente';
}

function erasmusCleanText(string $text): string
{
    $text = trim($text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function erasmusNormKey(string $text): string
{
    $text = erasmusCleanText($text);

    if (function_exists('mb_strtolower')) {
        $text = mb_strtolower($text, 'UTF-8');
    } else {
        $text = strtolower($text);
    }

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (is_string($converted) && $converted !== '') {
            $text = $converted;
        }
    }

    $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? $text;
    $text = trim($text);
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    return $text;
}

function erasmusFindStoryIndexByIstid(array $store, string $istid): int
{
    $istid = erasmusNormalizeIstid($istid);
    foreach (($store['stories'] ?? []) as $i => $story) {
        if (!is_array($story)) {
            continue;
        }
        if (erasmusNormalizeIstid((string)($story['istid'] ?? '')) === $istid) {
            return (int)$i;
        }
    }
    return -1;
}

function erasmusFindStoryIndexById(array $store, string $storyId): int
{
    $storyId = trim($storyId);
    foreach (($store['stories'] ?? []) as $i => $story) {
        if (!is_array($story)) {
            continue;
        }
        if (trim((string)($story['id'] ?? '')) === $storyId) {
            return (int)$i;
        }
    }
    return -1;
}

function erasmusFindUniversityIndexById(array $store, string $universityId): int
{
    $universityId = trim($universityId);
    foreach (($store['universities'] ?? []) as $i => $uni) {
        if (!is_array($uni)) {
            continue;
        }
        if (trim((string)($uni['id'] ?? '')) === $universityId) {
            return (int)$i;
        }
    }
    return -1;
}

function erasmusFindBestUniversityMatch(array $store, string $country, string $universityName): ?array
{
    $countryNorm = erasmusNormKey($country);
    $nameNorm = erasmusNormKey($universityName);
    if ($countryNorm === '' || $nameNorm === '') {
        return null;
    }

    $bestIdx = -1;
    $bestDistance = PHP_INT_MAX;
    $bestSimilarity = 0.0;

    foreach (($store['universities'] ?? []) as $idx => $uni) {
        if (!is_array($uni)) {
            continue;
        }

        $uniCountryNorm = erasmusNormKey((string)($uni['country'] ?? ''));
        if ($uniCountryNorm !== $countryNorm) {
            continue;
        }

        $uniNameNorm = erasmusNormKey((string)($uni['name'] ?? ''));
        if ($uniNameNorm === '') {
            continue;
        }

        if ($uniNameNorm === $nameNorm) {
            return [
                'index' => (int)$idx,
                'type' => 'exact',
                'distance' => 0,
                'similarity' => 100.0,
            ];
        }

        $distance = levenshtein($nameNorm, $uniNameNorm);
        similar_text($nameNorm, $uniNameNorm, $similarity);

        if ($distance < $bestDistance || ($distance === $bestDistance && $similarity > $bestSimilarity)) {
            $bestDistance = $distance;
            $bestSimilarity = (float)$similarity;
            $bestIdx = (int)$idx;
        }
    }

    if ($bestIdx < 0) {
        return null;
    }

    $dynamicThreshold = max(2, (int)floor(strlen($nameNorm) * 0.22));
    if ($bestDistance <= $dynamicThreshold || $bestSimilarity >= 85.0) {
        return [
            'index' => $bestIdx,
            'type' => 'closest',
            'distance' => $bestDistance,
            'similarity' => $bestSimilarity,
        ];
    }

    return null;
}

function erasmusDetectMime(string $path): string
{
    if (class_exists('finfo')) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        return (string)$fi->file($path);
    }

    if (function_exists('mime_content_type')) {
        return (string)mime_content_type($path);
    }

    return '';
}

function erasmusMimeExtension(string $mime): string
{
    switch ($mime) {
        case 'image/jpeg': return 'jpg';
        case 'image/png': return 'png';
        case 'image/gif': return 'gif';
        case 'image/webp': return 'webp';
        default: return 'bin';
    }
}

function erasmusStoreUploadedImage(array $file, string $prefix, string $targetDir, int $maxBytes): string
{
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed (code ' . $err . ').');
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException('Uploaded file is empty.');
    }
    if ($size > $maxBytes) {
        throw new RuntimeException('Uploaded image is larger than 2MB.');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Invalid uploaded file.');
    }

    $mime = erasmusDetectMime($tmp);
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mime, $allowedMimes, true)) {
        throw new RuntimeException('Invalid image format. Allowed: jpg, jpeg, png, gif, webp.');
    }

    $ext = erasmusMimeExtension($mime);
    $filename = $prefix . '_' . bin2hex(random_bytes(10)) . '.' . $ext;
    $dest = rtrim($targetDir, '/') . '/' . $filename;

    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('Failed to store uploaded image.');
    }

    return $filename;
}

function erasmusStoreUploadedImageOptional(array $file, string $prefix, string $targetDir, int $maxBytes): string
{
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    return erasmusStoreUploadedImage($file, $prefix, $targetDir, $maxBytes);
}

function erasmusDeleteFileIfExists(string $dir, string $filename): void
{
    $filename = trim($filename);
    if ($filename === '') {
        return;
    }

    $full = rtrim($dir, '/') . '/' . basename($filename);
    if (is_file($full)) {
        @unlink($full);
    }
}

function erasmusStorySummary(string $storyText, int $limit = 220): string
{
    $storyText = trim($storyText);
    if ($storyText === '') {
        return '';
    }

    $plain = preg_replace('/\s+/u', ' ', $storyText) ?? $storyText;
    $plain = trim($plain);

    if ($plain === '') {
        return '';
    }

    $len = function_exists('mb_strlen') ? mb_strlen($plain, 'UTF-8') : strlen($plain);
    if ($len <= $limit) {
        return $plain;
    }

    $slice = function_exists('mb_substr') ? mb_substr($plain, 0, $limit, 'UTF-8') : substr($plain, 0, $limit);
    $lastSpace = function_exists('mb_strrpos') ? mb_strrpos($slice, ' ', 0, 'UTF-8') : strrpos($slice, ' ');
    if ($lastSpace !== false && $lastSpace > 40) {
        $slice = function_exists('mb_substr') ? mb_substr($slice, 0, (int)$lastSpace, 'UTF-8') : substr($slice, 0, (int)$lastSpace);
    }

    return rtrim($slice, " \t\n\r\0\x0B.,;:") . '...';
}

function erasmusPublicStudentPhotoUrl(string $filename): string
{
    return siteUrl('/data/erasmus/student_photos/' . rawurlencode($filename));
}

function erasmusPublicUniversityPhotoUrl(string $filename): string
{
    return siteUrl('/data/erasmus/university_photos/' . rawurlencode($filename));
}

function erasmusStoriesCountForUniversity(array $store, string $universityId): int
{
    $count = 0;
    foreach (($store['stories'] ?? []) as $story) {
        if (!is_array($story)) {
            continue;
        }
        if ((string)($story['university_id'] ?? '') === $universityId) {
            $count++;
        }
    }
    return $count;
}
