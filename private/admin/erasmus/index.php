<?php
declare(strict_types=1);

require_once(__DIR__ . '/../auth.php');
require_once(__DIR__ . '/../private/roles.php');
require_once(__DIR__ . '/../private/erasmus_store.php');
require_once(__DIR__ . '/../private/logs.php');
require_once(__DIR__ . '/../private/blocked_erasmus.php');

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalizeStoryView(string $view): string
{
    $view = strtolower(trim($view));
    $allowed = ['review', 'pending', 'changes_requested', 'approved', 'rejected', 'all'];
    return in_array($view, $allowed, true) ? $view : 'review';
}

function storyMatchesView(string $status, string $view): bool
{
    if ($view === 'all') {
        return true;
    }
    if ($view === 'review') {
        return $status === 'pending' || $status === 'changes_requested';
    }
    if ($view === 'rejected') {
        return false;
    }
    return $status === $view;
}

$currentUser = erasmusNormalizeIstid((string)($_SESSION['user']['istid'] ?? ''));
$canManage = $currentUser !== '' && (isAdmin($currentUser) || isErasmusManager($currentUser));

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

$maxBytes = 2 * 1024 * 1024;

try {
    erasmusEnsureStorage();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Storage error: ' . $e->getMessage();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $movedUniPhoto = '';
    $movedStudentPhoto = '';
    $storySaved = false;

    try {
        $csrf = (string)($_POST['csrf'] ?? '');
        if (!hash_equals((string)$_SESSION['csrf'], $csrf)) {
            throw new RuntimeException('Invalid CSRF token. Please retry.');
        }

        $redirectStoryView = normalizeStoryView((string)($_POST['story_view'] ?? 'review'));

        $store = erasmusLoadStore();
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'story_create') {
            $studentName = erasmusCleanText((string)($_POST['student_name'] ?? ''));
            $istid = erasmusNormalizeIstid((string)($_POST['istid'] ?? ''));
            $storyText = trim((string)($_POST['story_text'] ?? ''));
            $storyStatus = trim((string)($_POST['status'] ?? 'pending'));
            if (!in_array($storyStatus, ['pending', 'approved', 'changes_requested'], true)) {
                $storyStatus = 'pending';
            }

            if ($studentName === '' || $storyText === '') {
                throw new InvalidArgumentException('Student name and story text are required.');
            }
            if (strlen($studentName) > 180 || strlen($istid) > 64) {
                throw new InvalidArgumentException('Student name or IST ID is too long.');
            }

            $uniId = trim((string)($_POST['university_id'] ?? ''));
            if ($uniId !== '') {
                $uIdx = erasmusFindUniversityIndexById($store, $uniId);
                if ($uIdx < 0) {
                    throw new RuntimeException('University not found.');
                }
            } else {
                $uniName = erasmusCleanText((string)($_POST['university_name'] ?? ''));
                $country = erasmusCleanText((string)($_POST['country'] ?? ''));
                if ($uniName === '' || $country === '') {
                    throw new InvalidArgumentException('Choose an existing university or provide a new university name and country.');
                }
                if (strlen($uniName) > 180 || strlen($country) > 120) {
                    throw new InvalidArgumentException('University name or country is too long.');
                }

                $existingUniId = '';
                $uniNameNorm = erasmusNormKey($uniName);
                $countryNorm = erasmusNormKey($country);
                foreach (($store['universities'] ?? []) as $uni) {
                    if (!is_array($uni)) {
                        continue;
                    }
                    if (erasmusNormKey((string)($uni['name'] ?? '')) === $uniNameNorm
                        && erasmusNormKey((string)($uni['country'] ?? '')) === $countryNorm) {
                        $existingUniId = (string)($uni['id'] ?? '');
                        break;
                    }
                }

                if ($existingUniId !== '') {
                    $uniId = $existingUniId;
                } else {
                    $uniId = bin2hex(random_bytes(16));
                    $store['universities'][] = [
                        'id' => $uniId,
                        'name' => $uniName,
                        'country' => $country,
                        'photo' => '',
                        'created_at' => time(),
                        'updated_at' => time(),
                    ];
                }
            }

            $storyId = bin2hex(random_bytes(16));
            $story = [
                'id' => $storyId,
                'university_id' => $uniId,
                'student_name' => $studentName,
                'istid' => $istid,
                'story_text' => $storyText,
                'status' => $storyStatus,
                'admin_note' => trim((string)($_POST['admin_note'] ?? '')),
                'student_photo' => '',
                'created_at' => time(),
                'updated_at' => time(),
            ];

            if ($storyStatus !== 'pending') {
                $story['reviewed_at'] = time();
                $story['reviewed_by'] = $currentUser;
            }

            if ($storyStatus === 'changes_requested' && trim((string)$story['admin_note']) === '') {
                throw new InvalidArgumentException('A note is required when creating a story with changes requested status.');
            }

            if (isset($_FILES['student_photo']) && is_array($_FILES['student_photo'])) {
                $err = (int)($_FILES['student_photo']['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($err !== UPLOAD_ERR_NO_FILE) {
                    $movedStudentPhoto = erasmusStoreUploadedImage((array)$_FILES['student_photo'], 'student', erasmusStudentPhotosDir(), $maxBytes);
                    $story['student_photo'] = $movedStudentPhoto;
                }
            }

            $store['stories'][] = $story;
            erasmusSaveStore($store);
            $storySaved = true;

            saveLog($currentUser, 'erasmus_story_created', [
                'story_id' => $storyId,
                'university_id' => $uniId,
                'status' => $storyStatus,
            ]);

            $_SESSION['flash_ok'] = 'Story created.';
            $redirectStoryView = $storyStatus;
        } elseif ($action === 'story_approve') {
            $storyId = trim((string)($_POST['story_id'] ?? ''));
            $storyIdx = erasmusFindStoryIndexById($store, $storyId);
            if ($storyIdx < 0) {
                throw new RuntimeException('Story not found.');
            }

            $store['stories'][$storyIdx]['status'] = 'approved';
            $store['stories'][$storyIdx]['admin_note'] = trim((string)($_POST['admin_note'] ?? ''));
            $store['stories'][$storyIdx]['reviewed_at'] = time();
            $store['stories'][$storyIdx]['reviewed_by'] = $currentUser;
            $store['stories'][$storyIdx]['updated_at'] = time();

            erasmusSaveStore($store);
            saveLog($currentUser, 'erasmus_story_approved', ['story_id' => $storyId]);
            $_SESSION['flash_ok'] = 'Story approved.';
        } elseif ($action === 'story_request_changes') {
            $storyId = trim((string)($_POST['story_id'] ?? ''));
            $storyIdx = erasmusFindStoryIndexById($store, $storyId);
            if ($storyIdx < 0) {
                throw new RuntimeException('Story not found.');
            }

            $note = trim((string)($_POST['admin_note'] ?? ''));
            if ($note === '') {
                throw new InvalidArgumentException('Please provide a note when requesting changes.');
            }

            $store['stories'][$storyIdx]['status'] = 'changes_requested';
            $store['stories'][$storyIdx]['admin_note'] = $note;
            $store['stories'][$storyIdx]['reviewed_at'] = time();
            $store['stories'][$storyIdx]['reviewed_by'] = $currentUser;
            $store['stories'][$storyIdx]['updated_at'] = time();

            erasmusSaveStore($store);
            saveLog($currentUser, 'erasmus_story_changes_requested', ['story_id' => $storyId]);
            $_SESSION['flash_ok'] = 'Changes requested for story.';
        } elseif ($action === 'story_reject') {
            $storyId = trim((string)($_POST['story_id'] ?? ''));
            $storyIdx = erasmusFindStoryIndexById($store, $storyId);
            if ($storyIdx < 0) {
                throw new RuntimeException('Story not found.');
            }

            $story = $store['stories'][$storyIdx];
            $studentPhoto = trim((string)($story['student_photo'] ?? ''));
            $uniId = trim((string)($story['university_id'] ?? ''));
            $istid = erasmusNormalizeIstid((string)($story['istid'] ?? ''));
            $reason = trim((string)($_POST['block_reason'] ?? ''));

            unset($store['stories'][$storyIdx]);
            $store['stories'] = array_values($store['stories']);

            erasmusDeleteFileIfExists(erasmusStudentPhotosDir(), $studentPhoto);

            if ($uniId !== '' && erasmusStoriesCountForUniversity($store, $uniId) === 0) {
                $uIdx = erasmusFindUniversityIndexById($store, $uniId);
                if ($uIdx >= 0) {
                    $u = $store['universities'][$uIdx] ?? null;
                    if (is_array($u)) {
                        $uPhoto = trim((string)($u['photo'] ?? ''));
                        erasmusDeleteFileIfExists(erasmusUniversityPhotosDir(), $uPhoto);
                    }
                    unset($store['universities'][$uIdx]);
                    $store['universities'] = array_values($store['universities']);
                }
            }

            if ($istid !== '') {
                erasmusAddBlockedUser($istid, $reason);
            }

            erasmusSaveStore($store);
            saveLog($currentUser, 'erasmus_story_rejected', ['story_id' => $storyId, 'istid' => $istid]);
            $_SESSION['flash_ok'] = 'Story rejected and user added to the blocked list.';
        } elseif ($action === 'stories_bulk') {
            $bulkAction = trim((string)($_POST['bulk_action'] ?? ''));
            if (!in_array($bulkAction, ['approve', 'request_changes', 'reject'], true)) {
                throw new InvalidArgumentException('Invalid bulk action.');
            }

            $rawIds = $_POST['story_ids'] ?? [];
            if (!is_array($rawIds)) {
                throw new InvalidArgumentException('No stories selected.');
            }

            $storyIds = [];
            foreach ($rawIds as $id) {
                if (!is_string($id)) continue;
                $id = trim($id);
                if ($id === '') continue;
                $storyIds[$id] = true;
            }
            $storyIds = array_keys($storyIds);

            if (empty($storyIds)) {
                throw new InvalidArgumentException('Select at least one story.');
            }

            $note = trim((string)($_POST['admin_note'] ?? ''));
            if ($bulkAction === 'request_changes' && $note === '') {
                throw new InvalidArgumentException('A note is required when requesting changes in bulk.');
            }

            $processed = 0;
            $approved = 0;
            $changes = 0;
            $rejected = 0;

            foreach ($storyIds as $storyId) {
                $storyIdx = erasmusFindStoryIndexById($store, $storyId);
                if ($storyIdx < 0) {
                    continue;
                }

                if ($bulkAction === 'approve') {
                    $store['stories'][$storyIdx]['status'] = 'approved';
                    $store['stories'][$storyIdx]['admin_note'] = $note;
                    $store['stories'][$storyIdx]['reviewed_at'] = time();
                    $store['stories'][$storyIdx]['reviewed_by'] = $currentUser;
                    $store['stories'][$storyIdx]['updated_at'] = time();
                    $approved++;
                    $processed++;
                    continue;
                }

                if ($bulkAction === 'request_changes') {
                    $store['stories'][$storyIdx]['status'] = 'changes_requested';
                    $store['stories'][$storyIdx]['admin_note'] = $note;
                    $store['stories'][$storyIdx]['reviewed_at'] = time();
                    $store['stories'][$storyIdx]['reviewed_by'] = $currentUser;
                    $store['stories'][$storyIdx]['updated_at'] = time();
                    $changes++;
                    $processed++;
                    continue;
                }

                $story = $store['stories'][$storyIdx];
                $studentPhoto = trim((string)($story['student_photo'] ?? ''));
                $uniId = trim((string)($story['university_id'] ?? ''));
                $istid = erasmusNormalizeIstid((string)($story['istid'] ?? ''));
                $reason = trim((string)($_POST['block_reason'] ?? ''));

                unset($store['stories'][$storyIdx]);
                erasmusDeleteFileIfExists(erasmusStudentPhotosDir(), $studentPhoto);

                if ($uniId !== '' && erasmusStoriesCountForUniversity($store, $uniId) === 0) {
                    $uIdx = erasmusFindUniversityIndexById($store, $uniId);
                    if ($uIdx >= 0) {
                        $u = $store['universities'][$uIdx] ?? null;
                        if (is_array($u)) {
                            $uPhoto = trim((string)($u['photo'] ?? ''));
                            erasmusDeleteFileIfExists(erasmusUniversityPhotosDir(), $uPhoto);
                        }
                        unset($store['universities'][$uIdx]);
                    }
                }

                if ($istid !== '') {
                    erasmusAddBlockedUser($istid, $reason);
                }

                $rejected++;
                $processed++;
            }

            $store['stories'] = array_values($store['stories']);
            $store['universities'] = array_values($store['universities']);

            erasmusSaveStore($store);
            saveLog($currentUser, 'erasmus_stories_bulk', [
                'bulk_action' => $bulkAction,
                'selected' => count($storyIds),
                'processed' => $processed,
                'approved' => $approved,
                'changes_requested' => $changes,
                'rejected' => $rejected,
            ]);

            if ($processed === 0) {
                $_SESSION['flash_ok'] = 'No matching stories were updated.';
            } elseif ($bulkAction === 'approve') {
                $_SESSION['flash_ok'] = 'Approved ' . $approved . ' story(ies).';
            } elseif ($bulkAction === 'request_changes') {
                $_SESSION['flash_ok'] = 'Requested changes for ' . $changes . ' story(ies).';
            } else {
                $_SESSION['flash_ok'] = 'Rejected ' . $rejected . ' story(ies) and deleted files.';
            }
        } elseif ($action === 'blocked_user_add') {
            $istid = erasmusNormalizeIstid((string)($_POST['istid'] ?? ''));
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($istid === '') {
                throw new InvalidArgumentException('IST ID is required.');
            }

            erasmusAddBlockedUser($istid, $reason);
            $_SESSION['flash_ok'] = 'Blocked user added.';
        } elseif ($action === 'blocked_user_remove') {
            $istid = erasmusNormalizeIstid((string)($_POST['istid'] ?? ''));
            if ($istid === '') {
                throw new InvalidArgumentException('IST ID is required.');
            }

            erasmusRemoveBlockedUser($istid);
            $_SESSION['flash_ok'] = 'Blocked user removed.';
        } elseif ($action === 'university_update') {
            $uniId = trim((string)($_POST['university_id'] ?? ''));
            $uIdx = erasmusFindUniversityIndexById($store, $uniId);
            if ($uIdx < 0) {
                throw new RuntimeException('University not found.');
            }

            $name = erasmusCleanText((string)($_POST['university_name'] ?? ''));
            $country = erasmusCleanText((string)($_POST['country'] ?? ''));
            if ($name === '' || $country === '') {
                throw new InvalidArgumentException('University name and country are required.');
            }
            if (strlen($name) > 180 || strlen($country) > 120) {
                throw new InvalidArgumentException('University name or country is too long.');
            }

            $nameNorm = erasmusNormKey($name);
            $countryNorm = erasmusNormKey($country);

            foreach (($store['universities'] ?? []) as $i => $other) {
                if (!is_array($other) || (int)$i === $uIdx) {
                    continue;
                }
                if (erasmusNormKey((string)($other['name'] ?? '')) === $nameNorm
                    && erasmusNormKey((string)($other['country'] ?? '')) === $countryNorm) {
                    throw new InvalidArgumentException('Another university with the same country and name already exists.');
                }
            }

            $oldPhoto = trim((string)($store['universities'][$uIdx]['photo'] ?? ''));
            if (isset($_FILES['university_photo']) && is_array($_FILES['university_photo'])) {
                $err = (int)($_FILES['university_photo']['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($err !== UPLOAD_ERR_NO_FILE) {
                    $movedUniPhoto = erasmusStoreUploadedImage((array)$_FILES['university_photo'], 'uni', erasmusUniversityPhotosDir(), $maxBytes);
                    $store['universities'][$uIdx]['photo'] = $movedUniPhoto;
                }
            }

            $store['universities'][$uIdx]['name'] = $name;
            $store['universities'][$uIdx]['country'] = $country;
            $store['universities'][$uIdx]['updated_at'] = time();

            erasmusSaveStore($store);

            if ($movedUniPhoto !== '' && $oldPhoto !== '' && $oldPhoto !== $movedUniPhoto) {
                erasmusDeleteFileIfExists(erasmusUniversityPhotosDir(), $oldPhoto);
            }

            saveLog($currentUser, 'erasmus_university_updated', ['university_id' => $uniId]);
            $_SESSION['flash_ok'] = 'University updated.';
        } else {
            throw new RuntimeException('Unknown action.');
        }

        header('Location: ' . adminUrl('/private/admin/erasmus/index.php?story_view=' . rawurlencode($redirectStoryView)));
        exit;
    } catch (Throwable $e) {
        if ($movedUniPhoto !== '') {
            erasmusDeleteFileIfExists(erasmusUniversityPhotosDir(), $movedUniPhoto);
        }
        if ($movedStudentPhoto !== '' && !$storySaved) {
            erasmusDeleteFileIfExists(erasmusStudentPhotosDir(), $movedStudentPhoto);
        }

        $_SESSION['flash_error'] = 'Error: ' . $e->getMessage();
        $redirectStoryView = normalizeStoryView((string)($_POST['story_view'] ?? 'review'));
        header('Location: ' . adminUrl('/private/admin/erasmus/index.php?story_view=' . rawurlencode($redirectStoryView)));
        exit;
    }
}

$flashOk = $_SESSION['flash_ok'] ?? ($_SESSION['flash_success'] ?? '');
$flashErr = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_success'], $_SESSION['flash_error']);

$store = erasmusLoadStore();

$universities = is_array($store['universities'] ?? null) ? $store['universities'] : [];
$stories = is_array($store['stories'] ?? null) ? $store['stories'] : [];
$storyView = normalizeStoryView((string)($_GET['story_view'] ?? 'review'));

$uniById = [];
foreach ($universities as $u) {
    if (!is_array($u)) {
        continue;
    }
    $id = (string)($u['id'] ?? '');
    if ($id !== '') {
        $uniById[$id] = $u;
    }
}

usort($stories, static function ($a, $b): int {
    if (!is_array($a) || !is_array($b)) return 0;
    return (int)($b['updated_at'] ?? 0) <=> (int)($a['updated_at'] ?? 0);
});

$blockedUsers = erasmusLoadBlockedUsers();
$storyCounts = [
    'review' => 0,
    'pending' => 0,
    'changes_requested' => 0,
    'approved' => 0,
    'rejected' => 0,
    'all' => 0,
];

$storiesFiltered = [];
foreach ($stories as $story) {
    if (!is_array($story)) {
        continue;
    }

    $status = erasmusNormalizeStatus((string)($story['status'] ?? 'pending'));
    $storyCounts['all']++;

    if ($status === 'pending') {
        $storyCounts['pending']++;
        $storyCounts['review']++;
    } elseif ($status === 'changes_requested') {
        $storyCounts['changes_requested']++;
        $storyCounts['review']++;
    } elseif ($status === 'approved') {
        $storyCounts['approved']++;
    }

    if (storyMatchesView($status, $storyView)) {
        $storiesFiltered[] = $story;
    }
}

$rejectedHistoryCount = 0;
foreach (loadLogs() as $log) {
    if (!is_array($log)) {
        continue;
    }

    $action = (string)($log['action'] ?? '');
    if ($action === 'erasmus_story_rejected') {
        $rejectedHistoryCount++;
        continue;
    }

    if ($action === 'erasmus_stories_bulk') {
        $details = $log['details'] ?? null;
        if (is_array($details)) {
            $rejectedHistoryCount += max(0, (int)($details['rejected'] ?? 0));
        }
    }
}
$storyCounts['rejected'] = $rejectedHistoryCount;

$storyViewLabels = [
    'review' => 'Review',
    'pending' => 'Pending',
    'changes_requested' => 'Changes Requested',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'all' => 'All',
];
$storyViewLabel = $storyViewLabels[$storyView] ?? 'Review';

usort($universities, static function ($a, $b): int {
    $ca = is_array($a) ? (string)($a['country'] ?? '') : '';
    $cb = is_array($b) ? (string)($b['country'] ?? '') : '';
    $cmp = strcasecmp($ca, $cb);
    if ($cmp !== 0) return $cmp;
    $na = is_array($a) ? (string)($a['name'] ?? '') : '';
    $nb = is_array($b) ? (string)($b['name'] ?? '') : '';
    return strcasecmp($na, $nb);
});

function erasmusStoryPreview(string $txt): string
{
    return erasmusStorySummary($txt, 220);
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Erasmus — Admin</title>
    <link rel="stylesheet" href="<?= h(adminUrl('/private/admin/css/admin.css')) ?>" />
    <link rel="stylesheet" href="<?= h(adminUrl('/private/admin/erasmus/erasmus.css')) ?>" />
</head>
<body>
<div class="container">
    <div class="header">
        <div class="brand">
            <img class="loginLogo" style="width: 150px; height: auto;" src="<?= h(adminUrl('/private/admin/images/logocorhorizontal-2.png')) ?>" alt="NEB" />
            <div>
                <h1 class="title">Erasmus Admin</h1>
                <p class="subtitle">Moderar histórias e gerir universidades</p>
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
        <div class="card" style="grid-column: span 12;">
            <h2>Inject story</h2>
            <div class="subtitle">Create a story manually and send it through the same moderation flow.</div>
            <div class="uniActions" style="margin-top: 12px;">
                <button class="button primary" type="button" id="openInjectStoryDialog">Inject story</button>
            </div>

            <dialog id="injectStoryDialog" class="injectStoryDialog">
                <div class="injectStoryDialogInner">
                    <div class="injectStoryDialogHeader">
                        <h3>Inject story</h3>
                        <button class="button" type="button" id="closeInjectStoryDialog">Close</button>
                    </div>

                    <form method="post" action="<?= h(adminUrl('/private/admin/erasmus/index.php')) ?>" enctype="multipart/form-data" class="uniForm" id="injectStoryForm">
                        <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                        <input type="hidden" name="action" value="story_create" />
                        <input type="hidden" name="story_view" value="<?= h($storyView) ?>" />

                        <label class="field">
                            <span class="label">Student name</span>
                            <input type="text" name="student_name" maxlength="180" required />
                        </label>

                        <label class="field">
                            <span class="label">IST ID</span>
                            <input type="text" name="istid" maxlength="64" />
                        </label>

                        <label class="field">
                            <span class="label">Story text</span>
                            <textarea name="story_text" rows="6" required></textarea>
                        </label>

                        <label class="field">
                            <span class="label">Existing university</span>
                            <select name="university_id">
                                <option value="">Create a new university below</option>
                                <?php foreach ($universities as $uni): ?>
                                    <?php if (!is_array($uni)) continue; ?>
                                    <option value="<?= h((string)($uni['id'] ?? '')) ?>"><?= h((string)($uni['country'] ?? '')) ?> · <?= h((string)($uni['name'] ?? '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label class="field">
                            <span class="label">New university name</span>
                            <input type="text" name="university_name" maxlength="180" placeholder="Only used when no existing university is selected" />
                        </label>

                        <label class="field">
                            <span class="label">New university country</span>
                            <input type="text" name="country" maxlength="120" placeholder="Only used when no existing university is selected" />
                        </label>

                        <label class="field">
                            <span class="label">Initial status</span>
                            <select name="status">
                                <option value="pending">Pending</option>
                                <option value="approved">Approved</option>
                                <option value="changes_requested">Changes requested</option>
                            </select>
                        </label>

                        <label class="field">
                            <span class="label">Admin note</span>
                            <textarea name="admin_note" rows="2" placeholder="Optional unless the status is changes requested"></textarea>
                        </label>

                        <label class="field">
                            <span class="label">Student photo</span>
                            <input type="file" name="student_photo" accept="image/*" />
                        </label>

                        <div class="uniActions">
                            <button class="button primary" type="submit">Create story</button>
                        </div>
                    </form>
                </div>
            </dialog>
        </div>

        <div class="card" style="grid-column: span 12;">
            <h2>Stories · <?= h($storyViewLabel) ?></h2>

            <div class="storyTabs" role="navigation" aria-label="Story status pages">
                <a class="storyTab<?= $storyView === 'review' ? ' is-active' : '' ?>" href="<?= h(adminUrl('/private/admin/erasmus/index.php?story_view=review')) ?>">
                    <span>Review</span>
                    <span class="storyTabCount"><?= h((string)$storyCounts['review']) ?></span>
                </a>
                <a class="storyTab<?= $storyView === 'pending' ? ' is-active' : '' ?>" href="<?= h(adminUrl('/private/admin/erasmus/index.php?story_view=pending')) ?>">
                    <span>Pending</span>
                    <span class="storyTabCount"><?= h((string)$storyCounts['pending']) ?></span>
                </a>
                <a class="storyTab<?= $storyView === 'changes_requested' ? ' is-active' : '' ?>" href="<?= h(adminUrl('/private/admin/erasmus/index.php?story_view=changes_requested')) ?>">
                    <span>Changes Requested</span>
                    <span class="storyTabCount"><?= h((string)$storyCounts['changes_requested']) ?></span>
                </a>
                <a class="storyTab<?= $storyView === 'approved' ? ' is-active' : '' ?>" href="<?= h(adminUrl('/private/admin/erasmus/index.php?story_view=approved')) ?>">
                    <span>Approved</span>
                    <span class="storyTabCount"><?= h((string)$storyCounts['approved']) ?></span>
                </a>
                <a class="storyTab<?= $storyView === 'rejected' ? ' is-active' : '' ?>" href="<?= h(adminUrl('/private/admin/erasmus/index.php?story_view=rejected')) ?>">
                    <span>Rejected</span>
                    <span class="storyTabCount"><?= h((string)$storyCounts['rejected']) ?></span>
                </a>
                <a class="storyTab<?= $storyView === 'all' ? ' is-active' : '' ?>" href="<?= h(adminUrl('/private/admin/erasmus/index.php?story_view=all')) ?>">
                    <span>All</span>
                    <span class="storyTabCount"><?= h((string)$storyCounts['all']) ?></span>
                </a>
            </div>

            <?php if ($storyView === 'rejected'): ?>
                <div class="subtitle">Rejected stories are deleted from storage after moderation. Rejections recorded in logs: <?= h((string)$storyCounts['rejected']) ?>.</div>
            <?php elseif (empty($storiesFiltered)): ?>
                <div class="subtitle">No stories found for this status page.</div>
            <?php else: ?>
                <form id="bulkStoriesForm" class="bulkBar" method="post" action="<?= h(adminUrl('/private/admin/erasmus/index.php')) ?>">
                    <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                    <input type="hidden" name="action" value="stories_bulk" />
                    <input type="hidden" name="story_view" value="<?= h($storyView) ?>" />

                    <div class="bulkGrid">
                        <label class="bulkControl">
                            <span class="label">Bulk action</span>
                            <select name="bulk_action" id="bulkActionSelect" required>
                                <option value="approve">Approve selected</option>
                                <option value="request_changes">Request changes</option>
                                <option value="reject">Reject and delete</option>
                            </select>
                        </label>

                        <label class="bulkControl">
                            <span class="label">Admin note (required for request changes)</span>
                            <textarea name="admin_note" id="bulkNote" rows="2" placeholder="Optional note"></textarea>
                        </label>
                    </div>

                    <div class="bulkActions">
                        <button class="button" type="button" id="bulkSelectAll">Select all</button>
                        <button class="button" type="button" id="bulkClearAll">Clear</button>
                        <button class="button primary" type="submit">Apply to selected stories</button>
                    </div>

                    <label class="field" style="margin-top:12px;">
                        <span class="label">Block reason for rejected stories</span>
                        <textarea name="block_reason" id="bulkBlockReason" rows="2" placeholder="Optional reason to store for the blocked IST IDs"></textarea>
                    </label>

                    <div class="subtitle">Tip: only checked stories are affected by this bulk action.</div>
                </form>

                <div class="storyList">
                    <?php foreach ($storiesFiltered as $story): ?>
                        <?php if (!is_array($story)) continue; ?>
                        <?php
                            $storyId = (string)($story['id'] ?? '');
                            $uniId = (string)($story['university_id'] ?? '');
                            $uni = $uniById[$uniId] ?? null;
                            $status = erasmusNormalizeStatus((string)($story['status'] ?? 'pending'));
                            $studentPhoto = trim((string)($story['student_photo'] ?? ''));
                            $studentPhotoUrl = $studentPhoto !== '' ? siteUrl('/data/erasmus/student_photos/' . rawurlencode($studentPhoto)) : '';
                        ?>
                        <article class="storyCard status-<?= h($status) ?>">
                            <div class="storySelectRow">
                                <label class="storySelectLabel">
                                    <input class="storyBulkCheckbox" type="checkbox" name="story_ids[]" value="<?= h($storyId) ?>" form="bulkStoriesForm" />
                                    <span>Select story</span>
                                </label>
                                <span class="subtitle">ID: <?= h($storyId) ?></span>
                            </div>

                            <div class="storyTop">
                                <div>
                                    <h3><?= h((string)($story['student_name'] ?? 'Unknown')) ?> <span class="subtitle">(<?= h((string)($story['istid'] ?? '')) ?>)</span></h3>
                                    <div class="storySub">
                                        <span class="pill status-<?= h($status) ?>"><?= h(erasmusStatusLabel($status)) ?></span>
                                        <span><?= h((string)($uni['country'] ?? '—')) ?> · <?= h((string)($uni['name'] ?? 'Unknown university')) ?></span>
                                        <span>Updated: <?= h(date('Y-m-d H:i', (int)($story['updated_at'] ?? 0))) ?></span>
                                    </div>
                                </div>
                                <?php if ($studentPhotoUrl !== ''): ?>
                                    <img class="storyPhoto" src="<?= h($studentPhotoUrl) ?>" alt="Student photo" />
                                <?php endif; ?>
                            </div>

                            <div class="storyPreview"><?= h(erasmusStoryPreview((string)($story['story_text'] ?? ''))) ?></div>

                            <details class="storyDetails">
                                <summary>Read full story</summary>
                                <div class="storyFull"><?= nl2br(h((string)($story['story_text'] ?? ''))) ?></div>
                            </details>

                            <div class="moderationActions">
                                <form method="post" action="<?= h(adminUrl('/private/admin/erasmus/index.php')) ?>">
                                    <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                                    <input type="hidden" name="action" value="story_approve" />
                                    <input type="hidden" name="story_id" value="<?= h($storyId) ?>" />
                                    <input type="hidden" name="story_view" value="<?= h($storyView) ?>" />
                                    <textarea name="admin_note" rows="2" placeholder="Optional note to keep with this approval"><?= h((string)($story['admin_note'] ?? '')) ?></textarea>
                                    <button class="button primary" type="submit">Approve</button>
                                </form>

                                <form method="post" action="<?= h(adminUrl('/private/admin/erasmus/index.php')) ?>">
                                    <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                                    <input type="hidden" name="action" value="story_request_changes" />
                                    <input type="hidden" name="story_id" value="<?= h($storyId) ?>" />
                                    <input type="hidden" name="story_view" value="<?= h($storyView) ?>" />
                                    <textarea name="admin_note" rows="2" placeholder="Required: explain what should change" required><?= h((string)($story['admin_note'] ?? '')) ?></textarea>
                                    <button class="button" type="submit">Request changes</button>
                                </form>

                                <form method="post" action="<?= h(adminUrl('/private/admin/erasmus/index.php')) ?>" onsubmit="return confirm('Reject this story and block the student from submitting again?');">
                                    <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                                    <input type="hidden" name="action" value="story_reject" />
                                    <input type="hidden" name="story_id" value="<?= h($storyId) ?>" />
                                    <input type="hidden" name="story_view" value="<?= h($storyView) ?>" />
                                    <textarea name="block_reason" rows="2" placeholder="Reason for blocking this IST ID" style="margin-bottom:8px; width:100%;"></textarea>
                                    <button class="button danger" type="submit">Reject (delete)</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card" style="grid-column: span 12;">
            <h2>Universities</h2>
            <?php if (empty($universities)): ?>
                <div class="subtitle">No universities created yet.</div>
            <?php else: ?>
                <div class="uniGrid">
                    <?php foreach ($universities as $uni): ?>
                        <?php if (!is_array($uni)) continue; ?>
                        <?php
                            $uniId = (string)($uni['id'] ?? '');
                            $uniPhoto = trim((string)($uni['photo'] ?? ''));
                            $uniPhotoUrl = $uniPhoto !== '' ? siteUrl('/data/erasmus/university_photos/' . rawurlencode($uniPhoto)) : '';
                            $count = erasmusStoriesCountForUniversity($store, $uniId);
                        ?>
                        <article class="uniCard">
                            <div class="uniTop">
                                <?php if ($uniPhotoUrl !== ''): ?>
                                    <img src="<?= h($uniPhotoUrl) ?>" class="uniPhoto" alt="University" />
                                <?php else: ?>
                                    <div class="uniPlaceholder">No image</div>
                                <?php endif; ?>
                                <div>
                                    <div class="uniName"><?= h((string)($uni['name'] ?? '')) ?></div>
                                    <div class="subtitle"><?= h((string)($uni['country'] ?? '')) ?> · <?= h((string)$count) ?> story(ies)</div>
                                </div>
                            </div>

                            <form method="post" action="<?= h(adminUrl('/private/admin/erasmus/index.php')) ?>" enctype="multipart/form-data" class="uniForm">
                                <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                                <input type="hidden" name="action" value="university_update" />
                                <input type="hidden" name="university_id" value="<?= h($uniId) ?>" />
                                <input type="hidden" name="story_view" value="<?= h($storyView) ?>" />

                                <label class="field">
                                    <span class="label">University name</span>
                                    <input type="text" name="university_name" maxlength="180" value="<?= h((string)($uni['name'] ?? '')) ?>" required />
                                </label>

                                <label class="field">
                                    <span class="label">Country</span>
                                    <input type="text" name="country" maxlength="120" value="<?= h((string)($uni['country'] ?? '')) ?>" required />
                                </label>

                                <label class="field">
                                    <span class="label">Replace university image (max 2MB)</span>
                                    <input type="file" name="university_photo" accept="image/*" />
                                </label>

                                <div class="uniActions">
                                    <button class="button primary" type="submit">Save university</button>
                                </div>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card" style="grid-column: span 12;">
            <h2>Blocked Erasmus users</h2>
            <div class="subtitle">Manage the IST IDs that are blocked from submitting new stories.</div>
            <div class="uniActions" style="margin-top: 12px;">
                <button class="button primary" type="button" id="openBlockedUsersDialog">Manage blocked users</button>
            </div>

            <dialog id="blockedUsersDialog" class="injectStoryDialog">
                <div class="injectStoryDialogInner">
                    <div class="injectStoryDialogHeader">
                        <h3>Blocked Erasmus users</h3>
                        <button class="button" type="button" id="closeBlockedUsersDialog">Close</button>
                    </div>

                    <form method="post" action="<?= h(adminUrl('/private/admin/erasmus/index.php')) ?>" class="uniForm" style="margin-top:12px;">
                        <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                        <input type="hidden" name="action" value="blocked_user_add" />
                        <input type="hidden" name="story_view" value="<?= h($storyView) ?>" />
                        <div class="bulkGrid">
                            <label class="bulkControl">
                                <span class="label">IST ID</span>
                                <input type="text" name="istid" maxlength="64" required />
                            </label>
                            <label class="bulkControl">
                                <span class="label">Reason</span>
                                <input type="text" name="reason" maxlength="255" />
                            </label>
                        </div>
                        <div class="uniActions">
                            <button class="button primary" type="submit">Add blocked user</button>
                        </div>
                    </form>

                    <?php if (empty($blockedUsers)): ?>
                        <div class="subtitle" style="margin-top: 12px;">No blocked users yet.</div>
                    <?php else: ?>
                        <div class="uniGrid" style="margin-top: 12px;">
                            <?php foreach ($blockedUsers as $blocked): ?>
                                <?php if (!is_array($blocked)) continue; ?>
                                <?php $blockedIstid = (string)($blocked['istid'] ?? ''); ?>
                                <article class="uniCard">
                                    <div class="uniTop">
                                        <div>
                                            <div class="uniName"><?= h($blockedIstid) ?></div>
                                            <div class="subtitle"><?= h((string)($blocked['reason'] ?? 'No reason provided')) ?></div>
                                        </div>
                                    </div>
                                    <form method="post" action="<?= h(adminUrl('/private/admin/erasmus/index.php')) ?>" class="uniForm">
                                        <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                                        <input type="hidden" name="action" value="blocked_user_remove" />
                                        <input type="hidden" name="istid" value="<?= h($blockedIstid) ?>" />
                                        <input type="hidden" name="story_view" value="<?= h($storyView) ?>" />
                                        <div class="uniActions">
                                            <button class="button danger" type="submit">Remove</button>
                                        </div>
                                    </form>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </dialog>
        </div>
    </div>
</div>
<script>
(() => {
    const injectDialog = document.getElementById('injectStoryDialog');
    const openInjectDialogBtn = document.getElementById('openInjectStoryDialog');
    const closeInjectDialogBtn = document.getElementById('closeInjectStoryDialog');
    const injectStoryForm = document.getElementById('injectStoryForm');
    const blockedUsersDialog = document.getElementById('blockedUsersDialog');
    const openBlockedUsersDialogBtn = document.getElementById('openBlockedUsersDialog');
    const closeBlockedUsersDialogBtn = document.getElementById('closeBlockedUsersDialog');

    if (injectDialog && openInjectDialogBtn) {
        openInjectDialogBtn.addEventListener('click', () => {
            if (typeof injectDialog.showModal === 'function') {
                injectDialog.showModal();
            }
        });
    }

    if (injectDialog && closeInjectDialogBtn) {
        closeInjectDialogBtn.addEventListener('click', () => {
            injectDialog.close();
        });
    }

    if (injectDialog) {
        injectDialog.addEventListener('click', (ev) => {
            const target = ev.target;
            if (!(target instanceof HTMLElement)) return;
            if (target === injectDialog) {
                injectDialog.close();
            }
        });
    }

    if (injectStoryForm) {
        injectStoryForm.addEventListener('submit', () => {
            if (injectDialog && injectDialog.open) {
                injectDialog.close();
            }
        });
    }

    if (blockedUsersDialog && openBlockedUsersDialogBtn) {
        openBlockedUsersDialogBtn.addEventListener('click', () => {
            if (typeof blockedUsersDialog.showModal === 'function') {
                blockedUsersDialog.showModal();
            }
        });
    }

    if (blockedUsersDialog && closeBlockedUsersDialogBtn) {
        closeBlockedUsersDialogBtn.addEventListener('click', () => {
            blockedUsersDialog.close();
        });
    }

    if (blockedUsersDialog) {
        blockedUsersDialog.addEventListener('click', (ev) => {
            const target = ev.target;
            if (!(target instanceof HTMLElement)) return;
            if (target === blockedUsersDialog) {
                blockedUsersDialog.close();
            }
        });
    }

    const bulkForm = document.getElementById('bulkStoriesForm');
    if (!bulkForm) return;

    const getBoxes = () => Array.from(document.querySelectorAll('.storyBulkCheckbox'));

    const selectAllBtn = document.getElementById('bulkSelectAll');
    const clearBtn = document.getElementById('bulkClearAll');
    const actionSelect = document.getElementById('bulkActionSelect');
    const noteInput = document.getElementById('bulkNote');

    const syncNoteRules = () => {
        if (!actionSelect || !noteInput) return;
        const requiresNote = actionSelect.value === 'request_changes';
        noteInput.required = requiresNote;
        noteInput.placeholder = requiresNote ? 'Required: explain what should change' : 'Optional note';
    };

    if (actionSelect) {
        actionSelect.addEventListener('change', syncNoteRules);
    }

    syncNoteRules();

    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', () => {
            getBoxes().forEach((box) => {
                box.checked = true;
            });
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            getBoxes().forEach((box) => {
                box.checked = false;
            });
        });
    }

    bulkForm.addEventListener('submit', (ev) => {
        const selected = getBoxes().some((box) => box.checked);
        if (!selected) {
            ev.preventDefault();
            alert('Select at least one story before applying a bulk action.');
            return;
        }

        if (!actionSelect) return;
        if (actionSelect.value === 'reject') {
            const ok = confirm('Reject selected stories and delete their files?');
            if (!ok) {
                ev.preventDefault();
            }
        }
    });
})();
</script>
</body>
</html>
