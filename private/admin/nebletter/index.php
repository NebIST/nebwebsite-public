<?php
declare(strict_types=1);

require_once(__DIR__ . '/../auth.php');
require_once(__DIR__ . '/../private/roles.php');
require_once(__DIR__ . '/../private/logs.php');

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$currentUser = (string)($_SESSION['user']['istid'] ?? '');
$canManage = ($currentUser !== '') && (isAdmin($currentUser) || isLetterEditor($currentUser));

if (!$canManage) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Not authorized</title></head><body>';
    echo '<h1>Not authorized</h1>';
    echo '<p>You are not allowed to access this page.</p>';
    echo '<p><button type="button" onclick="history.back()">Back</button> <a href="' . h(adminUrl('/private/admin/index.php')) . '">Admin</a></p>';
    echo '</body></html>';
    exit;
}

if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$storageRoot = __DIR__ . '/../../../data/nebletter';

// Limits for chunked uploads on CGI systems:
// - each HTTP request must be <= upload_max_filesize (2MB) and <= post_max_size (8MB)
// - total PDF size we accept: 20MB
$maxChunkBytes = 2 * 1024 * 1024;
$maxTotalBytes = 20 * 1024 * 1024;
$maxChunks = 64;

function uploadErrorMessage(int $code): string {
    $uploadMax = (string) ini_get('upload_max_filesize');
    $postMax = (string) ini_get('post_max_size');
    $hint = "Server limits: upload_max_filesize={$uploadMax}, post_max_size={$postMax}.";

    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
            return 'Upload too large for server configuration. ' . $hint;
        case UPLOAD_ERR_FORM_SIZE:
            return 'Upload too large for this form.';
        case UPLOAD_ERR_PARTIAL:
            return 'Upload was interrupted (partial upload).';
        case UPLOAD_ERR_NO_FILE:
            return 'No file was uploaded.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Server misconfigured: missing temporary directory.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Server error: failed to write uploaded file.';
        case UPLOAD_ERR_EXTENSION:
            return 'Upload stopped by a PHP extension.';
        default:
            return 'Upload failed (code ' . $code . '). ' . $hint;
    }
}

$months = [
    'january' => 1,
    'february' => 2,
    'march' => 3,
    'april' => 4,
    'may' => 5,
    'june' => 6,
    'july' => 7,
    'august' => 8,
    'september' => 9,
    'october' => 10,
    'november' => 11,
    'december' => 12,
];

$monthLabels = [];
foreach ($months as $name => $num) {
    $monthLabels[(int) $num] = ucfirst((string) $name);
}

function ensureDir(string $dir): void {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create storage directory.');
        }
    }
}

function validateYearMonth(int $year, int $month): void{
    if ($year < 2000 || $year > 2100) {
        throw new InvalidArgumentException('Invalid year.');
    }
    if ($month < 1 || $month > 12) {
        throw new InvalidArgumentException('Invalid month.');
    }
}

function validateYearMonthName(int $year, string $monthName, array $months): void {
    if ($year < 2000 || $year > 2100) {
        throw new InvalidArgumentException('Invalid year.');
    }
    $monthName = strtolower(trim($monthName));
    if ($monthName === '' || !isset($months[$monthName])) {
        throw new InvalidArgumentException('Invalid month.');
    }
}

function destFilename(int $year, string $monthName): string {
    $monthName = strtolower(trim($monthName));
    return sprintf('%04d_%s.pdf', $year, $monthName);
}

function jsonResponse(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function isValidUploadId(string $id): bool {
    return (bool)preg_match('/^[a-f0-9]{32}$/', $id);
}

function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    $items = @scandir($dir);
    if (!is_array($items)) return;
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') continue;
        $p = $dir . '/' . $it;
        if (is_dir($p)) rrmdir($p);
        else @unlink($p);
    }
    @rmdir($dir);
}

function cleanupOldChunkDirs(string $chunksRoot, int $maxAgeSeconds = 86400): void {
    if (!is_dir($chunksRoot)) return;
    $items = @scandir($chunksRoot);
    if (!is_array($items)) return;
    $now = time();
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') continue;
        $dir = $chunksRoot . '/' . $it;
        if (!is_dir($dir)) continue;
        $manifest = $dir . '/manifest.json';
        $ts = @filemtime($manifest);
        if ($ts === false) $ts = @filemtime($dir);
        if ($ts !== false && ($now - (int)$ts) > $maxAgeSeconds) {
            rrmdir($dir);
        }
    }
}

function listPublishedLetters(string $storageRoot): array {
    $letters = [];
    $root = rtrim($storageRoot, '/');

    if (!is_dir($root)) {
        return [];
    }

    $files = @scandir($root) ?: [];
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        if (!str_ends_with(strtolower($file), '.pdf')) {
            continue;
        }

        if (!preg_match('/^(\d{4})_([a-z]+)\.pdf$/i', $file, $m)) {
            continue;
        }

        $letters[] = [
            'year' => (int) $m[1],
            'monthName' => strtolower((string) $m[2]),
            'filename' => $file,
            'path' => $root . '/' . $file,
            'url' => siteUrl(sprintf('/data/nebletter/%s', rawurlencode($file))),
        ];
    }

    usort($letters, function ($a, $b) {
        if ($a['year'] === $b['year']) {
            return strcmp((string) $b['monthName'], (string) $a['monthName']);
        }
        return $b['year'] <=> $a['year'];
    });

    return $letters;
}

function readRecentNebletterLogs(int $limit = 60): array {
    $path = __DIR__ . '/../private/logs.data.json';
    if (!is_file($path)) return [];

    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) return [];

    // Support either: [ ...entries ] OR { items: [ ...entries ] }
    $items = $decoded;
    if (isset($decoded['items']) && is_array($decoded['items'])) {
        $items = $decoded['items'];
    }

    $out = [];
    foreach ($items as $row) {
        if (!is_array($row)) continue;

        $action = (string)($row['action'] ?? '');
        if (!str_starts_with($action, 'nebletter_')) continue;

        $out[] = $row;
    }

    // newest first if there is a timestamp
    usort($out, function ($a, $b) {
        $ta = (int)($a['timestamp'] ?? 0);
        $tb = (int)($b['timestamp'] ?? 0);
        return $tb <=> $ta;
    });

    return array_slice($out, 0, $limit);
}

try {
    ensureDir($storageRoot);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<p>' . h($e->getMessage()) . '</p>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF (JS chunk uploader also sends this)
    $csrf = $_POST['csrf'] ?? '';
    if (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) {
        // If it's a chunk request, return JSON instead of redirect.
        $maybeAction = (string)($_POST['action'] ?? '');
        if ($maybeAction === 'upload_chunk') {
            jsonResponse(['ok' => false, 'error' => 'csrf'], 403);
        }
        $_SESSION['flash_error'] = 'Invalid CSRF token. Please retry.';
        header('Location: ' . adminUrl('/private/admin/nebletter/index.php'));
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'upload_chunk') {
            // === Chunked upload with ACK (sequential) ===
            $chunksRoot = rtrim($storageRoot, '/') . '/.chunks';
            ensureDir($chunksRoot);
            cleanupOldChunkDirs($chunksRoot);

            $uploadId = (string)($_POST['upload_id'] ?? '');
            if (!isValidUploadId($uploadId)) {
                jsonResponse(['ok' => false, 'error' => 'invalid_upload_id'], 400);
            }

            $year = (int)($_POST['year'] ?? 0);
            $monthName = (string)($_POST['month'] ?? '');
            validateYearMonthName($year, $monthName, $months);

            $chunkIndex = (int)($_POST['chunk_index'] ?? -1);
            $chunkCount = (int)($_POST['chunk_count'] ?? 0);
            $totalSize = (int)($_POST['total_size'] ?? 0);

            if ($totalSize <= 0 || $totalSize > $maxTotalBytes) {
                jsonResponse(['ok' => false, 'error' => 'total_size', 'max_total' => $maxTotalBytes], 400);
            }
            if ($chunkCount < 1 || $chunkCount > $maxChunks) {
                jsonResponse(['ok' => false, 'error' => 'chunk_count', 'max_chunks' => $maxChunks], 400);
            }
            if ($chunkIndex < 0 || $chunkIndex >= $chunkCount) {
                jsonResponse(['ok' => false, 'error' => 'chunk_index'], 400);
            }

            if (!isset($_FILES['chunk']) || !is_array($_FILES['chunk'])) {
                jsonResponse(['ok' => false, 'error' => 'missing_chunk_file'], 400);
            }

            $f = $_FILES['chunk'];
            $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err !== UPLOAD_ERR_OK) {
                jsonResponse(['ok' => false, 'error' => 'upload', 'message' => uploadErrorMessage($err)], 400);
            }

            $size = (int)($f['size'] ?? 0);
            if ($size <= 0) jsonResponse(['ok' => false, 'error' => 'empty_chunk'], 400);
            if ($size > $maxChunkBytes) jsonResponse(['ok' => false, 'error' => 'chunk_too_large', 'max_chunk' => $maxChunkBytes], 400);

            $tmp = (string)($f['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                jsonResponse(['ok' => false, 'error' => 'invalid_uploaded_file'], 400);
            }

            $uploadDir = $chunksRoot . '/' . $uploadId;
            ensureDir($uploadDir);

            $manifestPath = $uploadDir . '/manifest.json';
            $manifest = null;

            if (is_file($manifestPath)) {
                $decoded = json_decode((string)file_get_contents($manifestPath), true);
                $manifest = is_array($decoded) ? $decoded : null;
            }

            if (!is_array($manifest)) {
                $manifest = [
                    'upload_id' => $uploadId,
                    'created_at' => time(),
                    'year' => $year,
                    'month' => strtolower(trim($monthName)),
                    'dest_name' => destFilename($year, $monthName),
                    'chunk_count' => $chunkCount,
                    'total_size' => $totalSize,
                    'next_expected' => 0,
                ];
                file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            // Lock metadata to one writer at a time
            $lockPath = $uploadDir . '/.lock';
            $lock = fopen($lockPath, 'c');
            if ($lock === false) jsonResponse(['ok' => false, 'error' => 'lock_open'], 500);
            if (!flock($lock, LOCK_EX)) {
                fclose($lock);
                jsonResponse(['ok' => false, 'error' => 'lock'], 500);
            }

            // Reload manifest inside lock
            $decoded = json_decode((string)file_get_contents($manifestPath), true);
            $manifest = is_array($decoded) ? $decoded : $manifest;

            // Ensure consistent metadata
            if (
                (int)($manifest['chunk_count'] ?? 0) !== $chunkCount ||
                (int)($manifest['total_size'] ?? 0) !== $totalSize ||
                (int)($manifest['year'] ?? 0) !== $year ||
                (string)($manifest['month'] ?? '') !== strtolower(trim($monthName))
            ) {
                flock($lock, LOCK_UN);
                fclose($lock);
                jsonResponse(['ok' => false, 'error' => 'manifest_mismatch'], 409);
            }

            $nextExpected = (int)($manifest['next_expected'] ?? 0);

            $partPath = $uploadDir . '/' . sprintf('%06d.part', $chunkIndex);

            // Idempotency: if chunk already received, ACK it.
            if ($chunkIndex < $nextExpected && is_file($partPath)) {
                flock($lock, LOCK_UN);
                fclose($lock);
                jsonResponse([
                    'ok' => true,
                    'ack' => $chunkIndex,
                    'already' => true,
                    'next_expected' => $nextExpected,
                    'done' => false,
                ]);
            }

            // ACK system: only accept the next chunk in order
            if ($chunkIndex !== $nextExpected) {
                flock($lock, LOCK_UN);
                fclose($lock);
                jsonResponse([
                    'ok' => false,
                    'error' => 'out_of_order',
                    'expected_next' => $nextExpected,
                ], 409);
            }

            // Store part
            $stage = $uploadDir . '/.' . bin2hex(random_bytes(8)) . '.upload';
            if (!move_uploaded_file($tmp, $stage)) {
                flock($lock, LOCK_UN);
                fclose($lock);
                jsonResponse(['ok' => false, 'error' => 'store_chunk_failed'], 500);
            }
            if (!rename($stage, $partPath)) {
                @unlink($stage);
                flock($lock, LOCK_UN);
                fclose($lock);
                jsonResponse(['ok' => false, 'error' => 'finalize_chunk_failed'], 500);
            }

            // Update manifest
            $manifest['next_expected'] = $nextExpected + 1;
            file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $done = false;
            $replaced = false;
            $destName = (string)($manifest['dest_name'] ?? destFilename($year, $monthName));
            $destPath = rtrim($storageRoot, '/') . '/' . $destName;

            // If last chunk: assemble and validate PDF
            if (($nextExpected + 1) >= $chunkCount) {
                $assembledStage = rtrim($storageRoot, '/') . '/.' . $uploadId . '.upload';
                $out = fopen($assembledStage, 'wb');
                if ($out === false) {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                    jsonResponse(['ok' => false, 'error' => 'assemble_open'], 500);
                }

                $written = 0;
                for ($i = 0; $i < $chunkCount; $i++) {
                    $p = $uploadDir . '/' . sprintf('%06d.part', $i);
                    if (!is_file($p)) {
                        fclose($out);
                        @unlink($assembledStage);
                        flock($lock, LOCK_UN);
                        fclose($lock);
                        jsonResponse(['ok' => false, 'error' => 'missing_part', 'missing_index' => $i], 409);
                    }

                    $in = fopen($p, 'rb');
                    if ($in === false) {
                        fclose($out);
                        @unlink($assembledStage);
                        flock($lock, LOCK_UN);
                        fclose($lock);
                        jsonResponse(['ok' => false, 'error' => 'part_open', 'index' => $i], 500);
                    }

                    while (!feof($in)) {
                        $buf = fread($in, 1024 * 1024);
                        if ($buf === false) break;
                        $n = fwrite($out, $buf);
                        if ($n === false) {
                            fclose($in);
                            fclose($out);
                            @unlink($assembledStage);
                            flock($lock, LOCK_UN);
                            fclose($lock);
                            jsonResponse(['ok' => false, 'error' => 'assemble_write'], 500);
                        }
                        $written += $n;
                        if ($written > $maxTotalBytes) {
                            fclose($in);
                            fclose($out);
                            @unlink($assembledStage);
                            flock($lock, LOCK_UN);
                            fclose($lock);
                            jsonResponse(['ok' => false, 'error' => 'assembled_too_large', 'max_total' => $maxTotalBytes], 400);
                        }
                    }
                    fclose($in);
                }
                fclose($out);

                if ($written !== $totalSize) {
                    @unlink($assembledStage);
                    flock($lock, LOCK_UN);
                    fclose($lock);
                    jsonResponse(['ok' => false, 'error' => 'size_mismatch', 'written' => $written, 'expected' => $totalSize], 409);
                }

                // Best-effort PDF MIME validation
                $mime = '';
                if (class_exists('finfo')) {
                    $fi = new finfo(FILEINFO_MIME_TYPE);
                    $mime = (string)$fi->file($assembledStage);
                }
                if ($mime !== '' && $mime !== 'application/pdf') {
                    @unlink($assembledStage);
                    flock($lock, LOCK_UN);
                    fclose($lock);
                    jsonResponse(['ok' => false, 'error' => 'invalid_pdf', 'mime' => $mime], 400);
                }

                $replaced = is_file($destPath);
                if (!rename($assembledStage, $destPath)) {
                    @unlink($assembledStage);
                    flock($lock, LOCK_UN);
                    fclose($lock);
                    jsonResponse(['ok' => false, 'error' => 'final_rename_failed'], 500);
                }

                saveLog($currentUser, 'nebletter_upload', [
                    'year' => $year,
                    'month' => strtolower(trim($monthName)),
                    'filename' => $destName,
                    'size' => $totalSize,
                    'replaced' => $replaced,
                    'chunked' => true,
                    'chunks' => $chunkCount,
                ]);

                rrmdir($uploadDir);
                $done = true;
            }

            flock($lock, LOCK_UN);
            fclose($lock);

            jsonResponse([
                'ok' => true,
                'ack' => $chunkIndex,
                'next_expected' => $nextExpected + 1,
                'done' => $done,
                'replaced' => $replaced,
                'dest_name' => $destName,
                'max_total' => $maxTotalBytes,
                'max_chunk' => $maxChunkBytes,
            ]);
        }

        // === Legacy non-chunked form upload (still limited by CGI/php.ini) ===
        if ($action === 'upload') {
            $year = (int)($_POST['year'] ?? 0);
            $monthName = (string)($_POST['month'] ?? '');
            validateYearMonthName($year, $monthName, $months);

            if (!isset($_FILES['pdf']) || !is_array($_FILES['pdf'])) {
                throw new RuntimeException('Missing PDF upload.');
            }

            $f = $_FILES['pdf'];
            $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err !== UPLOAD_ERR_OK) {
                throw new RuntimeException(uploadErrorMessage($err));
            }

            $size = (int)($f['size'] ?? 0);
            if ($size <= 0) throw new RuntimeException('Empty file.');
            if ($size > $maxChunkBytes) throw new RuntimeException('PDF too large for server per-request limit. Use the chunked uploader (max 20MB).');

            $tmp = (string)($f['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                throw new RuntimeException('Invalid uploaded file.');
            }

            // Verify PDF by MIME (best-effort) and extension
            $origName = (string)($f['name'] ?? '');
            if (!str_ends_with(strtolower($origName), '.pdf')) {
                throw new RuntimeException('Only .pdf files are allowed.');
            }

            $mime = '';
            if (class_exists('finfo')) {
                $fi = new finfo(FILEINFO_MIME_TYPE);
                $mime = (string)$fi->file($tmp);
            }
            if ($mime !== '' && $mime !== 'application/pdf') {
                throw new RuntimeException('Invalid file type. Expected PDF.');
            }

            $destName = destFilename($year, $monthName);
            $destPath = rtrim($storageRoot, '/') . '/' . $destName;

            $replaced = is_file($destPath);

            // move to a temp name in the same dir, then rename (more reliable)
            $stagePath = rtrim($storageRoot, '/') . '/.' . bin2hex(random_bytes(8)) . '.upload';
            if (!move_uploaded_file($tmp, $stagePath)) {
                throw new RuntimeException('Failed to store uploaded file.');
            }
            if (!rename($stagePath, $destPath)) {
                @unlink($stagePath);
                throw new RuntimeException('Failed to finalize stored PDF.');
            }

            saveLog($currentUser, 'nebletter_upload', [
                'year' => $year,
                'month' => strtolower(trim($monthName)),
                'filename' => $destName,
                'size' => $size,
                'replaced' => $replaced,
                'chunked' => false,
            ]);

            $_SESSION['flash_ok'] = $replaced ? 'PDF replaced successfully.' : 'PDF uploaded successfully.';
        } elseif ($action === 'delete') {
            $year = (int)($_POST['year'] ?? 0);
            $monthName = (string)($_POST['month'] ?? '');
            validateYearMonthName($year, $monthName, $months);

            // Delete by deterministic name (requirement: YYYY_monthname.pdf)
            $destName = destFilename($year, $monthName);
            $path = rtrim($storageRoot, '/') . '/' . $destName;

            if (!is_file($path)) {
                throw new RuntimeException('That PDF does not exist.');
            }

            if (!unlink($path)) {
                throw new RuntimeException('Failed to delete PDF.');
            }

            saveLog($currentUser, 'nebletter_delete', [
                'year' => $year,
                'month' => strtolower(trim($monthName)),
                'filename' => $destName,
            ]);

            $_SESSION['flash_ok'] = 'PDF deleted successfully.';
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        // If it was a chunk action, return JSON
        if ($action === 'upload_chunk') {
            jsonResponse(['ok' => false, 'error' => 'exception', 'message' => $e->getMessage()], 500);
        }
        $_SESSION['flash_error'] = $e->getMessage();
    }

    header('Location: ' . adminUrl('/private/admin/nebletter/index.php'));
    exit;
}

$letters = listPublishedLetters($storageRoot);
$logs = readRecentNebletterLogs(80);

$flashOk = $_SESSION['flash_ok'] ?? null;
$flashErr = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

$now = new DateTimeImmutable('now');
$defaultYear = (int)$now->format('Y');
$defaultMonth = (int)$now->format('n');
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>NebLetter Admin</title>
    <link rel="stylesheet" href="<?= h(adminUrl('/private/admin/css/admin.css')) ?>" />
    <link rel="stylesheet" href="<?= h(adminUrl('/private/admin/nebletter/nebletter.css')) ?>" />
</head>
<body>
<div class="container">
    <div class="header">
        <div class="brand">
            <img class="loginLogo" style="width: 150px; height: auto;" src="<?= htmlspecialchars(adminUrl('/private/admin/images/logocorhorizontal-2.png'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="NEB" />
            <div>
                <h1 class="title">NebLetter</h1>
                <p class="subtitle">Publicar ou Remover NebLetters</p>
            </div>
        </div>

        <div class="nav">
            <a class="button" href="<?= h(adminUrl('/private/admin/index.php')) ?>">Página Admin</a>
            <a class="button danger" href="<?= h(adminUrl('/private/admin/logout.php')) ?>">Logout</a>
        </div>
    </div>

    <?php if (is_string($flashOk) && $flashOk !== ''): ?>
        <div class="alert ok"><?= h($flashOk) ?></div>
    <?php endif; ?>
    <?php if (is_string($flashErr) && $flashErr !== ''): ?>
        <div class="alert err"><?= h($flashErr) ?></div>
    <?php endif; ?>

    <div class="grid">
        <div class="card half">
            <h2>Upload PDF</h2>
            <p class="subtitle" style="margin-top:0">
                Upload em <strong>chunks</strong> (compatível com limites CGI). Tamanho máximo: <strong><?= (int)($maxTotalBytes / (1024*1024)) ?>MB</strong>.
                Cada chunk: até <strong><?= (int)($maxChunkBytes / (1024*1024)) ?>MB</strong>. O ficheiro final será <code>YYYY_monthname.pdf</code>.
            </p>

            <form class="uploadForm" method="post" action="<?= h(adminUrl('/private/admin/nebletter/index.php')) ?>" enctype="multipart/form-data" id="uploadForm">
                <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                <input type="hidden" name="action" value="upload" />

                <div class="row">
                    <label class="field">
                        <span class="label">Ano</span>
                        <input type="number" name="year" min="2000" max="2100" value="<?= (int)$defaultYear ?>" required />
                    </label>

                    <label class="field">
                        <span class="label">Mês</span>
                        <select name="month" required>
                            <?php foreach ($months as $monthName => $monthNumber): ?>
                                <?php $monthNumber = (int) $monthNumber; ?>
                                <option value="<?= htmlspecialchars((string) $monthName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $monthNumber === $defaultMonth ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(ucfirst((string) $monthName), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                    (<?= sprintf('%02d', $monthNumber) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <label class="dropzone" id="dropzone" for="pdfInput" tabindex="0" aria-label="Solte o PDF aqui ou escolha um arquivo">
                    <div class="dzTitle">Arraste e solte o PDF para aqui</div>
                    <div class="dzSub">ou clique para escolher</div>
                    <div class="dzMeta" id="dzMeta">Nenhum arquivo selecionado</div>
                </label>

                <input class="fileInput" type="file" name="pdf" id="pdfInput" accept="application/pdf,.pdf" required />

                <div class="uploadProgress" id="uploadProgress" hidden>
                    <div class="uploadProgressRow">
                        <div class="uploadProgressLabel">Total</div>
                        <progress id="totalProgress" value="0" max="1"></progress>
                        <div class="uploadProgressText" id="totalProgressText">0%</div>
                    </div>
                    <div class="chunksProgressList" id="chunksProgressList"></div>
                    <div class="uploadProgressActions">
                        <button class="button" type="button" id="cancelUploadBtn">Cancel</button>
                    </div>
                </div>

                <div class="row actions">
                    <button class="button primary" type="submit" id="uploadBtn">Upload / Substituir</button>
                </div>
            </form>
        </div>

        <div class="card half">
            <h2>NebLetters Publicados</h2>

            <?php if (empty($letters)): ?>
                <div class="subtitle">Nenhum PDF encontrado em <code>/data/nebletter</code>.</div>
            <?php else: ?>
                <div class="tableWrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Ano</th>
                                <th>Mês</th>
                            <th>Filename</th>
                            <th>Open</th>
                            <th>Delete</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($letters as $l): ?>
                            <tr>
                                <td><?= (int)$l['year'] ?></td>
                                    <?php
                                        $monthName = (string) ($l['monthName'] ?? '');
                                        $mNum = isset($months[$monthName]) ? (int) $months[$monthName] : 0;
                                    ?>
                                    <td>
                                        <?= htmlspecialchars(ucfirst($monthName), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                        <?php if ($mNum > 0): ?>
                                            <span style="opacity:.7">(<?= sprintf('%02d', $mNum) ?>)</span>
                                        <?php endif; ?>
                                    </td>
                                <td><code><?= h((string)$l['filename']) ?></code></td>
                                <td>
                                    <a class="button" href="<?= h((string)$l['url']) ?>" target="_blank" rel="noopener noreferrer">Open</a>
                                </td>
                                <td>
                                    <form method="post" action="<?= h(adminUrl('/private/admin/nebletter/index.php')) ?>"
                                          onsubmit="return confirm('Delete the PDF for <?= (int)$l['year'] ?>-<?= htmlspecialchars((string)($l['monthName'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>?');">
                                        <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                                        <input type="hidden" name="action" value="delete" />
                                        <input type="hidden" name="year" value="<?= (int)$l['year'] ?>" />
                                        <input type="hidden" name="month" value="<?= h((string)($l['monthName'] ?? '')) ?>" />
                                        <button class="button danger" type="submit">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card" style="grid-column: span 12;">
            <h2>Recent changes (logs)</h2>
            <?php if (empty($logs)): ?>
                <div class="subtitle">Nenhuma entrada de log do NebLetter encontrada.</div>
            <?php else: ?>
                <div class="logList">
                    <?php foreach ($logs as $row): ?>
                        <?php
                            $ts = (int)($row['timestamp'] ?? 0);
                            $when = $ts > 0 ? date('Y-m-d H:i:s', $ts) : 'hora desconhecida';
                            $actor = (string)($row['istId'] ?? '');
                            $action = (string)($row['action'] ?? '');
                            $details = $row['details'] ?? null;
                            $detailsJson = is_array($details) ? json_encode($details, JSON_UNESCAPED_SLASHES) : '';
                        ?>
                        <div class="logRow">
                            <div class="logMain">
                                <div class="logTitle">
                                    <span class="logAction"><?= h($action) ?></span>
                                    <span class="logWhen"><?= h($when) ?></span>
                                </div>
                                <div class="logMeta">
                                    <span class="logActor">por <code><?= h($actor) ?></code></span>
                                    <?php if (is_string($detailsJson) && $detailsJson !== ''): ?>
                                        <span class="logJson"><code><?= h($detailsJson) ?></code></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
  const dz = document.getElementById('dropzone');
  const input = document.getElementById('pdfInput');
  const meta = document.getElementById('dzMeta');

  const form = document.getElementById('uploadForm');
  const uploadBtn = document.getElementById('uploadBtn');

  const progressWrap = document.getElementById('uploadProgress');
  const totalProgress = document.getElementById('totalProgress');
  const totalProgressText = document.getElementById('totalProgressText');
  const chunksList = document.getElementById('chunksProgressList');
  const cancelBtn = document.getElementById('cancelUploadBtn');

  const MAX_TOTAL = <?= (int)$maxTotalBytes ?>;
  const CHUNK_SIZE = 1024 * 1024; // 1MB (safe under upload_max_filesize=2MB and post_max_size=8MB)
  const MAX_CHUNKS = <?= (int)$maxChunks ?>;

  function fmtBytes(n) {
    if (!Number.isFinite(n) || n <= 0) return '0 B';
    const units = ['B','KB','MB','GB'];
    const i = Math.min(units.length - 1, Math.floor(Math.log(n) / Math.log(1024)));
    const val = n / Math.pow(1024, i);
    return (i === 0 ? val.toFixed(0) : val.toFixed(2)) + ' ' + units[i];
  }

  function setMeta(file) {
    if (!file) {
      meta.textContent = 'Nenhum arquivo selecionado';
      dz.classList.remove('hasFile');
      return;
    }
    meta.textContent = file.name + ' • ' + fmtBytes(file.size);
    dz.classList.add('hasFile');
  }

  input.addEventListener('change', () => setMeta(input.files && input.files[0]));

  ['dragenter','dragover'].forEach((evt) => {
    dz.addEventListener(evt, (e) => {
      e.preventDefault();
      e.stopPropagation();
      dz.classList.add('dragging');
    });
  });

  ['dragleave','drop'].forEach((evt) => {
    dz.addEventListener(evt, (e) => {
      e.preventDefault();
      e.stopPropagation();
      dz.classList.remove('dragging');
    });
  });

  dz.addEventListener('drop', (e) => {
    const files = e.dataTransfer && e.dataTransfer.files;
    if (!files || !files.length) return;

    const file = files[0];
    const dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    setMeta(file);
  });

  function hex32() {
    const b = new Uint8Array(16);
    crypto.getRandomValues(b);
    return Array.from(b).map(x => x.toString(16).padStart(2,'0')).join('');
  }

  function makeChunkRow(i, count, chunkBytes) {
    const row = document.createElement('div');
    row.className = 'chunkRow';

    const label = document.createElement('div');
    label.className = 'chunkLabel';
    label.textContent = `Chunk ${i + 1}/${count} • ${fmtBytes(chunkBytes)}`;

    const prog = document.createElement('progress');
    prog.className = 'chunkProgress';
    prog.max = 1;
    prog.value = 0;

    const txt = document.createElement('div');
    txt.className = 'chunkText';
    txt.textContent = '0%';

    row.appendChild(label);
    row.appendChild(prog);
    row.appendChild(txt);

    return { row, prog, txt };
  }

  function xhrPostFormData(url, formData, onProgress) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', url, true);

      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable && typeof onProgress === 'function') {
          onProgress(e.loaded / e.total);
        }
      };

      xhr.onreadystatechange = () => {
        if (xhr.readyState !== 4) return;
        if (xhr.status >= 200 && xhr.status < 300) {
          try {
            resolve(JSON.parse(xhr.responseText || '{}'));
          } catch (e) {
            reject(new Error('Invalid JSON response'));
          }
        } else {
          let msg = 'HTTP ' + xhr.status;
          try {
            const j = JSON.parse(xhr.responseText || '{}');
            if (j && j.message) msg = j.message;
          } catch {}
          reject(new Error(msg));
        }
      };

      xhr.onerror = () => reject(new Error('Network error'));
      xhr.send(formData);

      // allow caller to abort by storing xhr on promise
      resolve._xhr = xhr;
    });
  }

  let cancelled = false;
  let currentXhr = null;

  cancelBtn.addEventListener('click', () => {
    cancelled = true;
    if (currentXhr && typeof currentXhr.abort === 'function') currentXhr.abort();
    cancelBtn.disabled = true;
    cancelBtn.textContent = 'Cancelling...';
  });

  form.addEventListener('submit', async (e) => {
    // Use chunked upload; keep the form for non-JS fallback.
    e.preventDefault();

    cancelled = false;
    cancelBtn.disabled = false;
    cancelBtn.textContent = 'Cancel';

    const file = input.files && input.files[0] ? input.files[0] : null;
    if (!file) {
      setMeta(null);
      return;
    }

    if (file.size > MAX_TOTAL) {
      meta.textContent = `${file.name} • ${fmtBytes(file.size)} — demasiado grande (max ${fmtBytes(MAX_TOTAL)})`;
      dz.classList.remove('hasFile');
      return;
    }

    if (file.type && file.type !== 'application/pdf') {
      meta.textContent = `${file.name} • tipo inválido (use PDF)`;
      dz.classList.remove('hasFile');
      return;
    }

    const year = form.querySelector('input[name="year"]').value;
    const month = form.querySelector('select[name="month"]').value;
    const csrf = form.querySelector('input[name="csrf"]').value;

    const chunkCount = Math.ceil(file.size / CHUNK_SIZE) || 1;
    if (chunkCount > MAX_CHUNKS) {
      meta.textContent = `${file.name} — demasiados chunks (${chunkCount} > ${MAX_CHUNKS}).`;
      return;
    }

    const uploadId = hex32();
    const actionUrl = form.getAttribute('action') || window.location.href;

    // UI init
    progressWrap.hidden = false;
    chunksList.innerHTML = '';
    totalProgress.max = file.size;
    totalProgress.value = 0;
    totalProgressText.textContent = '0%';
    uploadBtn.disabled = true;

    let uploadedBytes = 0;

    try {
      for (let i = 0; i < chunkCount; i++) {
        if (cancelled) throw new Error('Cancelled');

        const start = i * CHUNK_SIZE;
        const end = Math.min(file.size, start + CHUNK_SIZE);
        const blob = file.slice(start, end);

        const { row, prog, txt } = makeChunkRow(i, chunkCount, blob.size);
        chunksList.appendChild(row);

        let attempt = 0;
        while (true) {
          if (cancelled) throw new Error('Cancelled');
          attempt++;

          const fd = new FormData();
          fd.append('csrf', csrf);
          fd.append('action', 'upload_chunk');
          fd.append('upload_id', uploadId);
          fd.append('year', year);
          fd.append('month', month);
          fd.append('chunk_index', String(i));
          fd.append('chunk_count', String(chunkCount));
          fd.append('total_size', String(file.size));
          fd.append('chunk', blob, `chunk_${String(i).padStart(6,'0')}.part`);

          prog.value = 0;
          txt.textContent = attempt > 1 ? `0% (retry ${attempt - 1})` : '0%';

          try {
            const p = xhrPostFormData(actionUrl, fd, (ratio) => {
              prog.value = ratio;
              txt.textContent = Math.round(ratio * 100) + '%';

              // optimistic total progress based on current chunk progress
              const optimistic = uploadedBytes + Math.round(blob.size * ratio);
              totalProgress.value = optimistic;
              totalProgressText.textContent = Math.min(100, Math.round((optimistic / file.size) * 100)) + '%';
            });

            currentXhr = p._xhr || null;
            const res = await p;

            if (res && res.ok === true && res.ack === i) {
              // chunk accepted
              prog.value = 1;
              txt.textContent = '100%';
              uploadedBytes += blob.size;

              totalProgress.value = uploadedBytes;
              totalProgressText.textContent = Math.min(100, Math.round((uploadedBytes / file.size) * 100)) + '%';

              if (res.done) {
                // finished on server
                window.location.href = actionUrl;
                return;
              }
              break;
            }

            // ACK / out-of-order handling
            if (res && res.error === 'out_of_order' && Number.isFinite(res.expected_next)) {
              // Server expects another chunk index; adjust loop to match
              i = Math.max(-1, Number(res.expected_next) - 1);
              // remove this row (since it didn't correspond to what server wants)
              row.remove();
              break;
            }

            throw new Error((res && (res.message || res.error)) ? String(res.message || res.error) : 'Chunk rejected');
          } catch (err) {
            currentXhr = null;
            if (attempt >= 3) throw err;
            // small backoff
            await new Promise(r => setTimeout(r, 400 * attempt));
          }
        }
      }

      // If we reach here, server didn't finalize (should be rare); just reload.
      window.location.href = actionUrl;
    } catch (err) {
      if (String(err && err.message || err) !== 'Cancelled') {
        meta.textContent = 'Erro no upload: ' + String(err && err.message || err);
      } else {
        meta.textContent = 'Upload cancelado.';
      }
    } finally {
      uploadBtn.disabled = false;
      currentXhr = null;
    }
  });

  setMeta(input.files && input.files[0]);
})();
</script>
</body>
</html>
