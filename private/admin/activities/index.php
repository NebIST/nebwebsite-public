<?php
declare(strict_types=1);

require_once(__DIR__ . '/../auth.php');
require_once(__DIR__ . '/../private/roles.php');
require_once(__DIR__ . '/../private/logs.php');

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$currentUser = (string)($_SESSION['user']['istid'] ?? '');
$canManage = ($currentUser !== '' && (isAdmin($currentUser) || isActivityManager($currentUser)));

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

// Ensure CSRF exists (your handlers already validate it)
if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$storageRoot = __DIR__ . '/../../../data/activities';
$jsonInfoPath = $storageRoot . '/activities.info.json';
$maxBytes = 2 * 1024 * 1024;

// Ensure storage dir exists (repo may not include it)
if (!is_dir($storageRoot)) {
    if (!mkdir($storageRoot, 0755, true) && !is_dir($storageRoot)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Failed to create storage directory: ' . $storageRoot;
        exit;
    }
}

//ensure the json contains, per activity, the info:
// name: 'Activity Name'
// photoFileName: 'activity-file-name' (legacy primary photo)
// photoFileNames: ['activity-file-name-1', 'activity-file-name-2', ...] (preferred)
// description: 'Activity Description (Max 3000 chars)' (optional)
// active: bool
// link: 'https://activity-link.example.com' (optional)
// listPriority: int (from -5 to 5)
// whenToGoLive: timestamp
// timeToLive: timestamp (0 = never remove automatically)
// created_at: timestamp
// updated_at: timestamp
function createInfoBase(): void
{
    global $jsonInfoPath;
    if (!is_file($jsonInfoPath)) {
        file_put_contents($jsonInfoPath, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

function nameAlreadyExists(string $name) : bool
{
    global $jsonInfoPath;
    if (!is_file($jsonInfoPath)) {
        return false;
    }
    $data = json_decode((string)file_get_contents($jsonInfoPath), true);
    if (!is_array($data)) {
        return false;
    }
    foreach ($data as $activity) {
        if (isset($activity['name']) && $activity['name'] === $name) {
            return true;
        }
    }
    return false;
}

function photoFileNameAlreadyExists(string $filename) 
{
    global $jsonInfoPath;
    if (!is_file($jsonInfoPath)) {
        return false;
    }
    $data = json_decode((string)file_get_contents($jsonInfoPath), true);
    if (!is_array($data)) {
        return false;
    }
    foreach ($data as $activity) {
        if (isset($activity['photoFileName']) && is_string($activity['photoFileName']) && $activity['photoFileName'] === $filename) return true;

        if (isset($activity['photoFileNames']) && is_array($activity['photoFileNames'])) {
            foreach ($activity['photoFileNames'] as $p) {
                if (is_string($p) && $p === $filename) return true;
            }
        }
    }
    return false;
}

function validFileFormat(string $filename): bool
{
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, $allowedExtensions, true);
}

function listCurrentActivities(string $storageRoot): array
{
    $activities = [];
    $dirHandle = opendir($storageRoot);
    if ($dirHandle === false) {
        return $activities;
    }
    while (($entry = readdir($dirHandle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $fullPath = $storageRoot . '/' . $entry;
        if (is_dir($fullPath)) {
            $activities[] = $entry;
        }
    }
    closedir($dirHandle);

    usort($activities, function ($a, $b) {
        $priorityA = $a['listPriority'] ?? 0;
        $priorityB = $b['listPriority'] ?? 0;
        if ($priorityA === $priorityB) {
            return 0;
        }
        return ($priorityA > $priorityB) ? -1 : 1;
    });
    return $activities;
}

createInfoBase();
$activitiesData = json_decode((string)file_get_contents($jsonInfoPath), true);
if (!is_array($activitiesData)) {
    $activitiesData = [];
}

// Privacy: never expose IST IDs in the public activities info JSON.
// If older data contains it, scrub it immediately.
$scrubbed = false;
foreach ($activitiesData as $i => $activity) {
    if (is_array($activity) && array_key_exists('istid', $activity)) {
        unset($activitiesData[$i]['istid']);
        $scrubbed = true;
    }
}
if ($scrubbed) {
    file_put_contents($jsonInfoPath, json_encode($activitiesData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    if (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) {
        $_SESSION['flash_error'] = 'Invalid CSRF token. Please retry.';
        // FIX: was redirecting to /admin/nebletter/ in some versions
        header('Location: ' . adminUrl('/private/admin/activities/index.php'));
        exit;
    }

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'upload') {
            // Support new multi-file input (activity_photos[]) and legacy single-file (activity_photo)
            $uploads = [];
            if (isset($_FILES['activity_photos']) && is_array($_FILES['activity_photos'])) {
                $f = $_FILES['activity_photos'];
                if (
                    isset($f['name'], $f['type'], $f['tmp_name'], $f['error'], $f['size']) &&
                    is_array($f['name']) && is_array($f['tmp_name']) && is_array($f['error']) && is_array($f['size'])
                ) {
                    $count = count($f['name']);
                    for ($i = 0; $i < $count; $i++) {
                        $uploads[] = [
                            'name' => $f['name'][$i] ?? '',
                            'tmp_name' => $f['tmp_name'][$i] ?? '',
                            'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                            'size' => $f['size'][$i] ?? 0,
                        ];
                    }
                }
            } elseif (isset($_FILES['activity_photo']) && is_array($_FILES['activity_photo'])) {
                $f = $_FILES['activity_photo'];
                $uploads[] = [
                    'name' => $f['name'] ?? '',
                    'tmp_name' => $f['tmp_name'] ?? '',
                    'error' => $f['error'] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $f['size'] ?? 0,
                ];
            }

            // Filter out empty entries
            $uploads = array_values(array_filter($uploads, function ($u) {
                return is_array($u) && isset($u['name']) && is_string($u['name']) && trim($u['name']) !== '' && isset($u['tmp_name']) && is_string($u['tmp_name']) && trim($u['tmp_name']) !== '';
            }));

            if (empty($uploads)) {
                throw new InvalidArgumentException('No file uploaded.');
            }
            if (count($uploads) > 12) {
                throw new InvalidArgumentException('Too many photos. Max 12 files per activity.');
            }
            $activityName = (string)($_POST['activity_name'] ?? '');
            if ($activityName === '') {
                throw new InvalidArgumentException('Activity name is required.');
            }
            if (nameAlreadyExists($activityName)) {
                throw new InvalidArgumentException('An activity with this name already exists.');
            }
            $description = (string)($_POST['activity_description'] ?? '');
            if (strlen($description) > 3000) {
                throw new InvalidArgumentException('Description too long. Max 3000 characters.');
            }
            $link = (string)($_POST['activity_link'] ?? '');
            $listPriority = (int)($_POST['activity_list_priority'] ?? 0);
            if ($listPriority < -5 || $listPriority > 5) {
                throw new InvalidArgumentException('List priority must be between -5 and 5.');  
            }
            $whenRaw = trim((string)($_POST['activity_when_to_go_live'] ?? ''));
            $whenToGoLiveParsed = ($whenRaw !== '') ? strtotime($whenRaw) : time();
            if ($whenToGoLiveParsed === false) {
                throw new InvalidArgumentException('Data inválida em “Publicar a partir de”.');
            }
            $whenToGoLive = (int)$whenToGoLiveParsed;

            $ttlEnabled = ((string)($_POST['activity_ttl_enabled'] ?? '')) === '1';
            $ttlRaw = trim((string)($_POST['activity_time_to_live'] ?? ''));
            if (!$ttlEnabled) {
                $timeToLive = 0;
            } else {
                if ($ttlRaw === '') {
                    throw new InvalidArgumentException('A data em “Remover em” é obrigatória quando a remoção automática está ativa.');
                }
                $ttlParsed = strtotime($ttlRaw);
                if ($ttlParsed === false) {
                    throw new InvalidArgumentException('Data inválida em “Remover em”.');
                }
                $timeToLive = (int)$ttlParsed;
                if ($timeToLive <= $whenToGoLive) {
                    throw new InvalidArgumentException('“Remover em” deve ser depois de “Publicar a partir de”.');
                }
            }

            // Validate + move uploaded files
            $movedPaths = [];
            $photoFileNames = [];
            $seenThisUpload = [];
            try {
                foreach ($uploads as $u) {
                    $err = (int)($u['error'] ?? UPLOAD_ERR_NO_FILE);
                    if ($err !== UPLOAD_ERR_OK) {
                        throw new RuntimeException('File upload error code: ' . $err);
                    }
                    $size = (int)($u['size'] ?? 0);
                    if ($size <= 0) throw new RuntimeException('Empty file.');
                    if ($size > $maxBytes) throw new RuntimeException('File too large. Max 2MB per file.');

                    $originalName = (string)($u['name'] ?? '');
                    $base = basename($originalName);
                    if ($base === '') throw new InvalidArgumentException('Invalid file name.');
                    if (!validFileFormat($base)) {
                        throw new InvalidArgumentException('Invalid file format. Allowed: jpg, jpeg, png, gif, webp.');
                    }

                    if (isset($seenThisUpload[$base])) {
                        throw new InvalidArgumentException('Duplicate filename in upload: ' . $base);
                    }
                    $seenThisUpload[$base] = true;

                    if (photoFileNameAlreadyExists($base)) {
                        throw new InvalidArgumentException('A photo with this filename already exists: ' . $base . '. Please rename and try again.');
                    }

                    $destinationPath = $storageRoot . '/' . $base;
                    if (!move_uploaded_file((string)$u['tmp_name'], $destinationPath)) {
                        throw new RuntimeException('Failed to move uploaded file: ' . $base);
                    }
                    $movedPaths[] = $destinationPath;
                    $photoFileNames[] = $base;
                }
            } catch (Throwable $e) {
                foreach ($movedPaths as $p) {
                    if (is_string($p) && is_file($p)) {
                        @unlink($p);
                    }
                }
                throw $e;
            }

            if (empty($photoFileNames)) {
                throw new RuntimeException('No photos were saved.');
            }

            // Save activity info
            $newActivity = [
                'name' => $activityName,
                // Keep legacy field for compatibility, but store full list too
                'photoFileName' => $photoFileNames[0],
                'photoFileNames' => $photoFileNames,
                'description' => $description,
                'active' => true,
                'link' => $link,
                'listPriority' => $listPriority,
                'whenToGoLive' => $whenToGoLive,
                'timeToLive' => $timeToLive,
                'created_at' => time(),
                'updated_at' => time(),
            ];
            $activitiesData[] = $newActivity;
            file_put_contents($jsonInfoPath, json_encode($activitiesData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            saveLog($currentUser, 'activity_created', [
                'activity_name' => $activityName,
                'photo_file_name' => $photoFileNames[0],
                'photo_file_names_count' => count($photoFileNames),
                'description' => $description,
                'link' => $link,
                'list_priority' => $listPriority,
                'when_to_go_live' => $whenToGoLive,
                'time_to_live' => $timeToLive,
            ]);
            $_SESSION['flash_success'] = 'A atividade foi criada com sucesso.';
            header('Location: ' . adminUrl('/private/admin/activities/index.php'));


            exit;
        } elseif ($action === 'delete') {
            $activityName = (string)($_POST['activity_name'] ?? '');
            $istid = (string)($_SESSION['user']['istid'] ?? '');
            $found = false;

            foreach ($activitiesData as $index => $activity) {
                if (isset($activity['name']) && $activity['name'] === $activityName) {
                    // Delete all photo files (supports legacy single and new multi)
                    $photos = [];
                    if (isset($activity['photoFileNames']) && is_array($activity['photoFileNames'])) {
                        foreach ($activity['photoFileNames'] as $p) {
                            if (is_string($p) && trim($p) !== '') $photos[] = trim($p);
                        }
                    }
                    $legacy = isset($activity['photoFileName']) && is_string($activity['photoFileName']) ? trim($activity['photoFileName']) : '';
                    if ($legacy !== '') $photos[] = $legacy;
                    $photos = array_values(array_unique($photos));

                    foreach ($photos as $p) {
                        $photoPath = $storageRoot . '/' . $p;
                        if (is_file($photoPath)) {
                            @unlink($photoPath);
                        }
                    }
                    // Remove activity from data
                    unset($activitiesData[$index]);
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new InvalidArgumentException('Activity not found.');
            }
            // Reindex array
            $activitiesData = array_values($activitiesData);
            file_put_contents($jsonInfoPath, json_encode($activitiesData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            saveLog($istid, 'activity_deleted', ['activity_name' => $activityName]);
            $_SESSION['flash_success'] = 'A atividade foi eliminada com sucesso.';
            header('Location: ' . adminUrl('/private/admin/activities/index.php'));
            exit;
        } elseif ($action === 'toggle_active') {
            $activityName = (string)($_POST['activity_name'] ?? '');
            $istid = (string)($_SESSION['user']['istid'] ?? '');
            $found = false;

            foreach ($activitiesData as $index => $activity) {
                if (isset($activity['name']) && $activity['name'] === $activityName) {
                    // Toggle active state
                    $currentActive = $activity['active'];
                    $activitiesData[$index]['active'] = !$currentActive;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new InvalidArgumentException('Activity not found.');
            }
            file_put_contents($jsonInfoPath, json_encode($activitiesData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            saveLog($istid, 'activity_toggled_active', ['activity_name' => $activityName]);
            $_SESSION['flash_success'] = 'O estado da atividade foi alterado com sucesso.';
            header('Location: ' . adminUrl('/private/admin/activities/index.php'));
            exit;
        } elseif ($action === 'edit') {
            $name = (string)($_POST['activity_name'] ?? '');
            $istid = (string)($_SESSION['user']['istid'] ?? '');
            $found = false;
            
            foreach($activitiesData as $index => $activity) {
                if (isset($activity['name']) && $activity['name'] === $name) {
                    $description = (string)($_POST['activity_description'] ?? '');
                    if (strlen($description) > 3000) {
                        throw new InvalidArgumentException('Description too long. Max 3000 characters.');
                    }
                    $link = (string)($_POST['activity_link'] ?? '');
                    $listPriority = (int)($_POST['activity_list_priority'] ?? 0);
                    if ($listPriority < -5 || $listPriority > 5) {
                        throw new InvalidArgumentException('List priority must be between -5 and 5.');  
                    }
                    $whenRaw = trim((string)($_POST['activity_when_to_go_live'] ?? ''));
                    $whenToGoLiveParsed = ($whenRaw !== '') ? strtotime($whenRaw) : time();
                    if ($whenToGoLiveParsed === false) {
                        throw new InvalidArgumentException('Data inválida em “Publicar a partir de”.');
                    }
                    $whenToGoLive = (int)$whenToGoLiveParsed;

                    $ttlEnabled = ((string)($_POST['activity_ttl_enabled'] ?? '')) === '1';
                    $ttlRaw = trim((string)($_POST['activity_time_to_live'] ?? ''));
                    if (!$ttlEnabled) {
                        $timeToLive = 0;
                    } else {
                        if ($ttlRaw === '') {
                            throw new InvalidArgumentException('A data em “Remover em” é obrigatória quando a remoção automática está ativa.');
                        }
                        $ttlParsed = strtotime($ttlRaw);
                        if ($ttlParsed === false) {
                            throw new InvalidArgumentException('Data inválida em “Remover em”.');
                        }
                        $timeToLive = (int)$ttlParsed;
                        if ($timeToLive <= $whenToGoLive) {
                            throw new InvalidArgumentException('“Remover em” deve ser depois de “Publicar a partir de”.');
                        }
                    }

                    $activitiesData[$index]['description'] = $description;
                    $activitiesData[$index]['link'] = $link;
                    $activitiesData[$index]['listPriority'] = $listPriority;
                    $activitiesData[$index]['whenToGoLive'] = $whenToGoLive;
                    $activitiesData[$index]['timeToLive'] = $timeToLive;
                    $activitiesData[$index]['updated_at'] = time();
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new InvalidArgumentException('Activity not found.');
            }
            file_put_contents($jsonInfoPath, json_encode($activitiesData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            saveLog($istid, 'activity_edited', ['activity_name' => $name]);
            $_SESSION['flash_success'] = 'A atividade foi editada com sucesso.';
            header('Location: ' . adminUrl('/private/admin/activities/index.php'));
            exit;
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = 'Error: ' . $e->getMessage();
        header('Location: ' . adminUrl('/private/admin/activities/index.php'));
        exit;
    }
}

header('Content-Type: text/html; charset=utf-8');

$flashOk = $_SESSION['flash_success'] ?? ($_SESSION['flash_ok'] ?? '');
$flashErr = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_ok'], $_SESSION['flash_error']);

function activityPhotoUrl(string $photoFileName): string
{
    return siteUrl('/data/activities/' . rawurlencode($photoFileName));
}

// Sort newest updated first (UI only)
usort($activitiesData, function ($a, $b) {
    $ua = (int)($a['updated_at'] ?? 0);
    $ub = (int)($b['updated_at'] ?? 0);
    return $ub <=> $ua;
});

// Read recent activity logs (best-effort, tolerant to log schema)
function readRecentActivityLogs(int $limit = 50): array
{
    $path = __DIR__ . '/../private/logs.data.json';
    if (!is_file($path)) return [];

    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) return [];

    $items = $decoded;
    if (isset($decoded['items']) && is_array($decoded['items'])) $items = $decoded['items'];

    $out = [];
    foreach ($items as $row) {
        if (!is_array($row)) continue;
        $action = (string)($row['action'] ?? '');
        if (!str_starts_with($action, 'activity_')) continue;
        $out[] = $row;
    }

    usort($out, function ($a, $b) {
        $ta = (int)($a['timestamp'] ?? $a['ts'] ?? $a['time'] ?? 0);
        $tb = (int)($b['timestamp'] ?? $b['ts'] ?? $b['time'] ?? 0);
        return $tb <=> $ta;
    });

    return array_slice($out, 0, $limit);
}

$logs = readRecentActivityLogs(60);

function friendlyActivityAction(string $action): string
{
    switch ($action) {
        case 'activity_created': return 'Atividade criada';
        case 'activity_deleted': return 'Atividade eliminada';
        case 'activity_edited': return 'Atividade editada';
        case 'activity_toggled_active': return 'Estado alterado';
        default: return $action;
    }
}

function extractActivityNameFromDetails($details): string
{
    if (!is_array($details)) return '';
    $candidates = [
        'activity_name',
        'name',
        'activity',
    ];
    foreach ($candidates as $key) {
        if (isset($details[$key]) && is_string($details[$key]) && $details[$key] !== '') {
            return $details[$key];
        }
    }
    return '';
}

function buildActivityLogChips($details): array
{
    if (!is_array($details)) return [];
    $chips = [];
    $map = [
        'photo_file_name' => 'Foto',
        'photo_file_names_count' => 'Fotos',
        'link' => 'Link',
        'list_priority' => 'Prioridade',
        'when_to_go_live' => 'Publicar',
        'time_to_live' => 'Remover',
    ];
    foreach ($map as $key => $label) {
        if (!array_key_exists($key, $details)) continue;
        $val = $details[$key];
        if (is_array($val)) continue;
        if (is_bool($val)) $val = $val ? 'sim' : 'não';
        $valStr = trim((string)$val);
        if ($valStr === '' || $valStr === '0') continue;
        if (($key === 'when_to_go_live' || $key === 'time_to_live') && ctype_digit($valStr)) {
            $ts = (int)$valStr;
            if ($ts > 0) $valStr = date('Y-m-d H:i', $ts);
        }
        $chips[] = [$label, $valStr];
    }
    return $chips;
}

function calculateTotalMemoryUsage(): int
{
    $storageRoot = __DIR__ . '/../../../data/activities';
    $totalBytes = 0;

    if (!is_dir($storageRoot)) {
        return 0;
    }

    $dirHandle = opendir($storageRoot);
    if ($dirHandle === false) {
        return 0;
    }
    while (($entry = readdir($dirHandle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $fullPath = $storageRoot . '/' . $entry;
        if (is_file($fullPath)) {
            $totalBytes += filesize($fullPath);
        }
    }
    closedir($dirHandle);

    return $totalBytes;
}

$currentMemoryUsageBytes = calculateTotalMemoryUsage();
$currentMemoryUsage = '';
if ($currentMemoryUsageBytes > 0) {
    if ($currentMemoryUsageBytes >= 1024 * 1024) {
        $currentMemoryUsage = number_format($currentMemoryUsageBytes / (1024 * 1024), 2) . ' MB';
    } elseif ($currentMemoryUsageBytes >= 1024) {
        $currentMemoryUsage = number_format($currentMemoryUsageBytes / 1024, 2) . ' KB';
    } else {
        $currentMemoryUsage = $currentMemoryUsageBytes . ' bytes';
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Atividades — Admin</title>
    <link rel="stylesheet" href="<?= h(adminUrl('/private/admin/css/admin.css')) ?>" />
    <link rel="stylesheet" href="<?= h(adminUrl('/private/admin/activities/activities.css')) ?>" />
</head>
<body>
<div class="container">
    <div class="header">
        <div class="brand">
            <img class="loginLogo" style="width: 150px; height: auto;" src="<?= htmlspecialchars(adminUrl('/private/admin/images/logocorhorizontal-2.png'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="NEB" />
            <div>
                <h1 class="title">Atividades</h1>
                <p class="subtitle">Criar, editar, ativar/desativar e remover atividades</p>
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
            <h2>Criar atividade</h2>
            <p class="subtitle" style="margin-top:0">
                Pelo menos 1 foto obrigatória (max <strong><?= (int)($maxBytes / (1024*1024)) ?>MB</strong> por ficheiro). Formatos: jpg/jpeg/png/gif/webp.
            </p>

            <form method="post" action="<?= h(adminUrl('/private/admin/activities/index.php')) ?>" enctype="multipart/form-data" class="uploadForm">
                <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                <input type="hidden" name="action" value="upload" />

                <label class="field">
                    <span class="label">Nome da atividade</span>
                    <input type="text" name="activity_name" maxlength="80" required />
                </label>

                <label class="field">
                    <span class="label">Descrição (até 3000 caracteres)</span>
                    <textarea name="activity_description" maxlength="3000" rows="25" placeholder="Opcional"></textarea>
                </label>

                <div class="row">
                    <label class="field">
                        <span class="label">Link</span>
                        <input type="url" name="activity_link" placeholder="https://... (opcional)" />
                    </label>

                    <label class="field">
                        <span class="label">Prioridade (-5 a 5)</span>
                        <input type="number" name="activity_list_priority" min="-5" max="5" value="0" />
                    </label>
                </div>

                <div class="row">
                    <label class="field">
                        <span class="label">Publicar a partir de</span>
                        <input type="datetime-local" name="activity_when_to_go_live" />
                        <span class="help">Vazio = agora</span>
                    </label>

                    <label class="field">
                        <span class="label">Remover em</span>
                        <label class="help" style="display:flex;align-items:center;gap:8px;margin:0 0 6px;">
                            <input type="checkbox" name="activity_ttl_enabled" value="1" data-ttl-toggle />
                            <span>Remover automaticamente</span>
                        </label>
                        <div class="ttlWrap" data-ttl-wrap hidden>
                            <input type="datetime-local" name="activity_time_to_live" data-ttl-input disabled />
                            <span class="help">Data/hora em que a atividade deixa de aparecer.</span>
                        </div>
                        <span class="help">Se desmarcado, a atividade fica até ser eliminada.</span>
                    </label>
                </div>

                <label class="dropzone" id="dropzone" for="activityPhotos">
                    <div class="dzTitle">Arraste e solte as fotos aqui</div>
                    <div class="dzSub">ou clique para escolher</div>
                    <div class="dzMeta" id="dzMeta">Nenhum ficheiro selecionado</div>
                </label>
                <input class="fileInput" type="file" name="activity_photos[]" id="activityPhotos" accept="image/*" multiple required />

                <div class="row actions">
                    <button class="button primary" type="submit">Criar</button>
                </div>
            </form>
        </div>

        <div class="card half">
            <h2>Atividades atuais</h2> 
            <?php if ($currentMemoryUsageBytes > 100 * 1024 * 1024): ?>
                <div class="subtitle" style="margin-top:0; color: red;">Uso de memória atual: <?= h($currentMemoryUsage) ?></div>
            <?php endif; ?>
            
            

            <?php if (empty($activitiesData)): ?>
                <div class="subtitle">Ainda não existem atividades.</div>
            <?php else: ?>
                <div class="searchBar" aria-label="Pesquisar atividades">
                    <label class="field" style="margin:0;flex:1 1 260px;min-width:240px;">
                        <span class="label">Pesquisar</span>
                        <input type="search" id="activitySearch" placeholder="Nome, link, descrição…" autocomplete="off" />
                    </label>
                    <div class="searchMeta subtitle" id="activitySearchMeta"></div>
                </div>
                <div class="subtitle" id="activitySearchEmpty" style="display:none;margin-top:8px;">Sem resultados.</div>
                <div class="tableWrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Foto</th>
                            <th>Nome</th>
                            <th>Estado</th>
                            <th>Ações</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($activitiesData as $activity): ?>
                            <?php
                                $name = (string)($activity['name'] ?? '');
                                $photos = [];
                                if (isset($activity['photoFileNames']) && is_array($activity['photoFileNames'])) {
                                    foreach ($activity['photoFileNames'] as $p) {
                                        if (is_string($p) && trim($p) !== '') $photos[] = trim($p);
                                    }
                                }
                                $legacyPhoto = (string)($activity['photoFileName'] ?? '');
                                if ($legacyPhoto !== '' && !in_array($legacyPhoto, $photos, true)) $photos[] = $legacyPhoto;
                                $photos = array_values(array_unique($photos));

                                $photo = (string)($photos[0] ?? '');
                                $photoCount = count($photos);
                                $active = (bool)($activity['active'] ?? false);

                                $linkVal = (string)($activity['link'] ?? '');
                                $descVal = (string)($activity['description'] ?? '');
                                $rowSearch = trim($name . ' ' . $linkVal . ' ' . $descVal);
                                $rowSearch = preg_replace('/\s+/', ' ', $rowSearch) ?? $rowSearch;
                                $rowSearch = function_exists('mb_strtolower')
                                    ? mb_strtolower($rowSearch, 'UTF-8')
                                    : strtolower($rowSearch);

                                $whenTs = (int)($activity['whenToGoLive'] ?? 0);
                                $whenVal = $whenTs > 0 ? date('Y-m-d\\TH:i', $whenTs) : '';

                                $ttlTs = (int)($activity['timeToLive'] ?? 0);
                                $ttlEnabled = $ttlTs > 0;
                                $ttlVal = $ttlEnabled ? date('Y-m-d\\TH:i', $ttlTs) : '';
                            ?>
                            <tr data-search="<?= h($rowSearch) ?>">
                                <td>
                                    <?php if ($photo !== ''): ?>
                                        <span class="thumbWrap">
                                            <img class="thumb" src="<?= h(activityPhotoUrl($photo)) ?>" alt="<?= h($name) ?>" />
                                            <?php if ($photoCount > 1): ?>
                                                <span class="thumbBadge" title="<?= h((string)$photoCount) ?> fotos">+<?= h((string)($photoCount - 1)) ?></span>
                                            <?php endif; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="subtitle">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-weight:650"><?= h($name) ?></div>
                                    <?php if ($linkVal !== ''): ?>
                                        <div class="subtitle"><a href="<?= h($linkVal) ?>" target="_blank" rel="noopener noreferrer">Abrir link</a></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($active): ?>
                                        <span class="pill on">Ativa</span>
                                    <?php else: ?>
                                        <span class="pill off">Inativa</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="actionsInline">
                                        <form method="post" action="<?= h(adminUrl('/private/admin/activities/index.php')) ?>">
                                            <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                                            <input type="hidden" name="action" value="toggle_active" />
                                            <input type="hidden" name="activity_name" value="<?= h($name) ?>" />
                                            <button class="button" type="submit"><?= $active ? 'Desativar' : 'Ativar' ?></button>
                                        </form>

                                        <details class="editBox">
                                            <summary class="button">Editar</summary>
                                            <form method="post" action="<?= h(adminUrl('/private/admin/activities/index.php')) ?>" class="editForm">
                                                <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                                                <input type="hidden" name="action" value="edit" />
                                                <input type="hidden" name="activity_name" value="<?= h($name) ?>" />

                                                <label class="field">
                                                    <span class="label">Descrição</span>
                                                    <textarea name="activity_description" maxlength="3000" rows="4"><?= h((string)($activity['description'] ?? '')) ?></textarea>
                                                </label>

                                                <div class="row">
                                                    <label class="field">
                                                        <span class="label">Link</span>
                                                        <input type="url" name="activity_link" value="<?= h((string)($activity['link'] ?? '')) ?>" />
                                                    </label>
                                                    <label class="field">
                                                        <span class="label">Prioridade</span>
                                                        <input type="number" name="activity_list_priority" min="-5" max="5" value="<?= (int)($activity['listPriority'] ?? 0) ?>" />
                                                    </label>
                                                </div>

                                                <div class="row">
                                                    <label class="field">
                                                        <span class="label">Publicar a partir de</span>
                                                        <input type="datetime-local" name="activity_when_to_go_live" value="<?= h($whenVal) ?>" />
                                                        <span class="help">Vazio = agora</span>
                                                    </label>
                                                    <label class="field">
                                                        <span class="label">Remover em</span>
                                                        <label class="help" style="display:flex;align-items:center;gap:8px;margin:0 0 6px;">
                                                            <input type="checkbox" name="activity_ttl_enabled" value="1" data-ttl-toggle <?= $ttlEnabled ? 'checked' : '' ?> />
                                                            <span>Remover automaticamente</span>
                                                        </label>
                                                        <div class="ttlWrap" data-ttl-wrap <?= $ttlEnabled ? '' : 'hidden' ?> >
                                                            <input type="datetime-local" name="activity_time_to_live" data-ttl-input value="<?= h($ttlVal) ?>" <?= $ttlEnabled ? '' : 'disabled' ?> />
                                                            <span class="help">Data/hora em que a atividade deixa de aparecer.</span>
                                                        </div>
                                                        <span class="help">Se desmarcado, a atividade fica até ser eliminada.</span>
                                                    </label>
                                                </div>

                                                <div class="row actions">
                                                    <button class="button primary" type="submit">Guardar</button>
                                                </div>
                                            </form>
                                        </details>

                                        <form method="post" action="<?= h(adminUrl('/private/admin/activities/index.php')) ?>"
                                              onsubmit="return confirm('Eliminar a atividade &quot;<?= h($name) ?>&quot;?');">
                                            <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                                            <input type="hidden" name="action" value="delete" />
                                            <input type="hidden" name="activity_name" value="<?= h($name) ?>" />
                                            <button class="button danger" type="submit">Eliminar</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card" style="grid-column: span 12;">
            <h2>Logs recentes</h2>
            <?php if (empty($logs)): ?>
                <div class="subtitle">Sem logs de atividades.</div>
            <?php else: ?>
                <div class="logList">
                    <?php foreach ($logs as $row): ?>
                        <?php
                            $ts = (int)($row['timestamp'] ?? $row['ts'] ?? $row['time'] ?? 0);
                            $when = $ts > 0 ? date('Y-m-d H:i:s', $ts) : 'hora desconhecida';
                            $actor = (string)($row['istId'] ?? $row['actor'] ?? $row['user'] ?? '');
                            $action = (string)($row['action'] ?? '');
                            $details = $row['details'] ?? $row['meta'] ?? null;
                            $actionLabel = friendlyActivityAction($action);
                            $activityName = extractActivityNameFromDetails($details);
                            $chips = buildActivityLogChips($details);
                            $pretty = is_array($details) ? json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
                        ?>
                        <div class="logRow">
                            <div class="logTitle">
                                <span class="logAction"><?= h($actionLabel) ?><?= $activityName !== '' ? ' • ' . h($activityName) : '' ?></span>
                                <span class="logWhen"><?= h($when) ?></span>
                            </div>
                            <div class="logMeta">
                                <span>por <code><?= h($actor) ?></code></span>
                            </div>

                            <?php if (!empty($chips)): ?>
                                <div class="logChips">
                                    <?php foreach ($chips as [$k, $v]): ?>
                                        <span class="logChip" title="<?= h($k . ': ' . $v) ?>"><span class="k"><?= h($k) ?>:</span> <?= h($v) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (is_string($pretty) && $pretty !== ''): ?>
                                <details class="logMore">
                                    <summary>Ver detalhes</summary>
                                    <pre class="logPre"><?= h($pretty) ?></pre>
                                </details>
                            <?php endif; ?>
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
    const input = document.getElementById('activityPhotos');
  const meta = document.getElementById('dzMeta');

    function bindTtlToggles() {
        const toggles = document.querySelectorAll('input[type="checkbox"][data-ttl-toggle]');
        toggles.forEach((cb) => {
            const field = cb.closest('.field');
            if (!field) return;
            const wrap = field.querySelector('[data-ttl-wrap]');
            const dt = field.querySelector('input[type="datetime-local"][data-ttl-input]');
            if (!dt) return;

            const sync = () => {
                const on = cb.checked;
                if (wrap) wrap.hidden = !on;
                dt.disabled = !on;
                if (on) dt.setAttribute('required', 'required');
                else {
                    dt.removeAttribute('required');
                    dt.value = '';
                }
            };

            cb.addEventListener('change', sync);
            sync();
        });
    }

    bindTtlToggles();

    function bindActivitySearch() {
        const search = document.getElementById('activitySearch');
        const metaEl = document.getElementById('activitySearchMeta');
        const emptyEl = document.getElementById('activitySearchEmpty');
        const tbody = document.querySelector('.tableWrap table.table tbody');
        if (!search || !tbody) return;

        const rows = Array.from(tbody.querySelectorAll('tr'));
        const total = rows.length;

        const update = () => {
            const q = (search.value || '').trim().toLowerCase();
            const terms = q ? q.split(/\s+/).filter(Boolean) : [];
            let shown = 0;

            rows.forEach((row) => {
                const hay = (row.getAttribute('data-search') || '').toLowerCase();
                const ok = terms.length === 0 ? true : terms.every(t => hay.includes(t));
                row.style.display = ok ? '' : 'none';
                if (ok) shown++;
            });

            if (metaEl) {
                metaEl.textContent = terms.length === 0 ? `${total} total` : `${shown} de ${total}`;
            }
            if (emptyEl) {
                emptyEl.style.display = shown === 0 ? '' : 'none';
            }
        };

        search.addEventListener('input', update);
        search.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                search.value = '';
                update();
                search.blur();
            }
        });

        update();
    }

    bindActivitySearch();

  function fmtBytes(n) {
    if (!Number.isFinite(n) || n <= 0) return '0 B';
    const units = ['B','KB','MB','GB'];
    const i = Math.min(units.length - 1, Math.floor(Math.log(n) / Math.log(1024)));
    const val = n / Math.pow(1024, i);
    return (i === 0 ? val.toFixed(0) : val.toFixed(2)) + ' ' + units[i];
  }

    function setMeta(files) {
        if (!files || !files.length) {
            meta.textContent = 'Nenhum ficheiro selecionado';
            dz.classList.remove('hasFile');
            return;
        }
        const totalBytes = files.reduce((acc, f) => acc + (f && f.size ? f.size : 0), 0);
        const firstNames = files.slice(0, 3).map(f => f.name).filter(Boolean);
        const suffix = files.length > 3 ? ` (+${files.length - 3})` : '';
        meta.textContent = `${files.length} ficheiro(s) • ${fmtBytes(totalBytes)} • ${firstNames.join(', ')}${suffix}`;
        dz.classList.add('hasFile');
    }

    input.addEventListener('change', () => setMeta(input.files ? Array.from(input.files) : []));

  ['dragenter','dragover'].forEach((evt) => {
    dz.addEventListener(evt, (e) => {
      e.preventDefault(); e.stopPropagation();
      dz.classList.add('dragging');
    });
  });

  ['dragleave','drop'].forEach((evt) => {
    dz.addEventListener(evt, (e) => {
      e.preventDefault(); e.stopPropagation();
      dz.classList.remove('dragging');
    });
  });

  dz.addEventListener('drop', (e) => {
    const files = e.dataTransfer && e.dataTransfer.files;
    if (!files || !files.length) return;

        const dt = new DataTransfer();
        Array.from(files).forEach((f) => dt.items.add(f));
        input.files = dt.files;
        setMeta(Array.from(input.files));
  });

    setMeta(input.files ? Array.from(input.files) : []);
})();
</script>
</body>
</html>