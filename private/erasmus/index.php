<?php
declare(strict_types=1);

require_once(__DIR__ . '/../bootstrap.php');
require_once(__DIR__ . '/../admin/private/erasmus_store.php');
require_once(__DIR__ . '/../admin/private/blocked_erasmus.php');

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$istid = erasmusNormalizeIstid((string)($_SESSION['user']['istid'] ?? ''));
if ($istid === '') {
    header('Location: ' . siteUrl('/private/oauth-start.php?next=/private/erasmus/index.php'));
    exit;
}

if (empty($_SESSION['erasmus_csrf']) || !is_string($_SESSION['erasmus_csrf'])) {
    $_SESSION['erasmus_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['erasmus_csrf'];

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
    $movedStudentPhoto = '';
    $movedUniPhoto = '';

    try {
        $token = (string)($_POST['csrf'] ?? '');
        if ($csrf === '' || !hash_equals($csrf, $token)) {
            throw new RuntimeException('Invalid CSRF token. Please refresh and retry.');
        }

        $store = erasmusLoadStore();

        $country = erasmusCleanText((string)($_POST['country'] ?? ''));
        $universityName = erasmusCleanText((string)($_POST['university_name'] ?? ''));
        $storyText = trim((string)($_POST['story_text'] ?? ''));

        if ($country === '' || $universityName === '') {
            throw new InvalidArgumentException('Country and university are required.');
        }
        if (strlen($country) > 120 || strlen($universityName) > 180) {
            throw new InvalidArgumentException('Country or university name is too long.');
        }

        $storyLen = function_exists('mb_strlen') ? mb_strlen($storyText, 'UTF-8') : strlen($storyText);
        if ($storyLen < 30) {
            throw new InvalidArgumentException('Your story is too short. Please write at least 30 characters.');
        }
        if ($storyLen > 9000) {
            throw new InvalidArgumentException('Your story exceeds 9000 characters.');
        }

        if (erasmusIsBlockedUser($istid)) {
            throw new RuntimeException('This IST ID is blocked from submitting Erasmus stories.');
        }

        $storyIdx = erasmusFindStoryIndexByIstid($store, $istid);
        $existingStory = ($storyIdx >= 0 && isset($store['stories'][$storyIdx]) && is_array($store['stories'][$storyIdx]))
            ? $store['stories'][$storyIdx]
            : null;

        $studentPhotoFilename = (string)($existingStory['student_photo'] ?? '');
        $hasStudentPhotoUpload = isset($_FILES['student_photo']) && is_array($_FILES['student_photo'])
            && (int)($_FILES['student_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if ($hasStudentPhotoUpload) {
            $movedStudentPhoto = erasmusStoreUploadedImage((array)$_FILES['student_photo'], 'student', erasmusStudentPhotosDir(), $maxBytes);
            $studentPhotoFilename = $movedStudentPhoto;
        }

        if ($studentPhotoFilename === '') {
            throw new InvalidArgumentException('Your personal photo is mandatory.');
        }

        $notePrefix = '';
        $match = erasmusFindBestUniversityMatch($store, $country, $universityName);
        if (is_array($match) && isset($match['index'])) {
            $uniIdx = (int)$match['index'];
            $uni = $store['universities'][$uniIdx] ?? null;
            if (!is_array($uni)) {
                throw new RuntimeException('Invalid university match.');
            }

            $universityId = (string)($uni['id'] ?? '');
            if ($universityId === '') {
                throw new RuntimeException('Matched university is missing an id.');
            }

            if (($match['type'] ?? '') === 'closest') {
                $notePrefix = 'University matched to existing entry: ' . (string)($uni['name'] ?? $universityName) . '. ';
            }

            if (isset($_FILES['university_photo']) && is_array($_FILES['university_photo'])) {
                $err = (int)($_FILES['university_photo']['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($err !== UPLOAD_ERR_NO_FILE && trim((string)($uni['photo'] ?? '')) === '') {
                    $movedUniPhoto = erasmusStoreUploadedImage((array)$_FILES['university_photo'], 'uni', erasmusUniversityPhotosDir(), $maxBytes);
                    $store['universities'][$uniIdx]['photo'] = $movedUniPhoto;
                    $store['universities'][$uniIdx]['updated_at'] = time();
                }
            }
        } else {
            $movedUniPhoto = '';
            if (isset($_FILES['university_photo']) && is_array($_FILES['university_photo'])) {
                $movedUniPhoto = erasmusStoreUploadedImageOptional((array)$_FILES['university_photo'], 'uni', erasmusUniversityPhotosDir(), $maxBytes);
            }

            $universityId = 'uni_' . bin2hex(random_bytes(9));
            $now = time();
            $store['universities'][] = [
                'id' => $universityId,
                'name' => $universityName,
                'country' => $country,
                'photo' => $movedUniPhoto,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $notePrefix = 'New university entry created. ';
        }

        $name = erasmusCleanText((string)($_SESSION['user']['name'] ?? $istid));
        if ($name === '') {
            $name = $istid;
        }

        $now = time();
        $storyRow = [
            'id' => (string)($existingStory['id'] ?? ('story_' . bin2hex(random_bytes(9)))),
            'istid' => $istid,
            'student_name' => $name,
            'student_email' => trim((string)($_SESSION['user']['email'] ?? '')),
            'university_id' => $universityId,
            'story_text' => $storyText,
            'story_summary' => erasmusStorySummary($storyText, 260),
            'student_photo' => $studentPhotoFilename,
            'status' => 'pending',
            'admin_note' => '',
            'submitted_at' => (int)($existingStory['submitted_at'] ?? $now),
            'updated_at' => $now,
            'reviewed_at' => 0,
            'reviewed_by' => '',
        ];

        if ($storyIdx >= 0) {
            $oldPhoto = (string)($existingStory['student_photo'] ?? '');
            $store['stories'][$storyIdx] = $storyRow;
            erasmusSaveStore($store);

            if ($movedStudentPhoto !== '' && $oldPhoto !== '' && $oldPhoto !== $movedStudentPhoto) {
                erasmusDeleteFileIfExists(erasmusStudentPhotosDir(), $oldPhoto);
            }

            $_SESSION['flash_ok'] = $notePrefix . 'Your story was updated and is now pending approval.';
        } else {
            $store['stories'][] = $storyRow;
            erasmusSaveStore($store);
            $_SESSION['flash_ok'] = $notePrefix . 'Your story was submitted successfully and is now pending approval.';
        }

        header('Location: ' . siteUrl('/private/erasmus/index.php'));
        exit;
    } catch (Throwable $e) {
        if ($movedStudentPhoto !== '') {
            erasmusDeleteFileIfExists(erasmusStudentPhotosDir(), $movedStudentPhoto);
        }
        if ($movedUniPhoto !== '') {
            erasmusDeleteFileIfExists(erasmusUniversityPhotosDir(), $movedUniPhoto);
        }

        $_SESSION['flash_error'] = $e->getMessage();
        header('Location: ' . siteUrl('/private/erasmus/index.php'));
        exit;
    }
}

$flashOk = $_SESSION['flash_ok'] ?? null;
$flashErr = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

$store = erasmusLoadStore();
$storyIdx = erasmusFindStoryIndexByIstid($store, $istid);
$currentStory = ($storyIdx >= 0 && isset($store['stories'][$storyIdx]) && is_array($store['stories'][$storyIdx]))
    ? $store['stories'][$storyIdx]
    : null;

$universityMap = [];
foreach (($store['universities'] ?? []) as $u) {
    if (!is_array($u)) {
        continue;
    }
    $id = (string)($u['id'] ?? '');
    if ($id !== '') {
        $universityMap[$id] = $u;
    }
}

$currentUniversity = null;
if ($currentStory !== null) {
    $uid = (string)($currentStory['university_id'] ?? '');
    if ($uid !== '' && isset($universityMap[$uid]) && is_array($universityMap[$uid])) {
        $currentUniversity = $universityMap[$uid];
    }
}

$countryValue = ($currentUniversity !== null)
    ? (string)($currentUniversity['country'] ?? '')
    : '';
$universityValue = ($currentUniversity !== null)
    ? (string)($currentUniversity['name'] ?? '')
    : '';
$storyValue = ($currentStory !== null)
    ? (string)($currentStory['story_text'] ?? '')
    : '';
$initialStoryLen = function_exists('mb_strlen') ? mb_strlen($storyValue, 'UTF-8') : strlen($storyValue);

$userDisplayName = erasmusCleanText((string)($_SESSION['user']['name'] ?? ''));
if ($userDisplayName === '') {
    $userDisplayName = 'estudante';
}
$firstNameParts = preg_split('/\s+/', $userDisplayName);
$firstName = (is_array($firstNameParts) && isset($firstNameParts[0])) ? trim((string)$firstNameParts[0]) : $userDisplayName;
if ($firstName === '') {
    $firstName = $userDisplayName;
}

$existingStudentPhoto = ($currentStory !== null) ? trim((string)($currentStory['student_photo'] ?? '')) : '';
$existingStudentPhotoUrl = ($existingStudentPhoto !== '')
    ? siteUrl('/data/erasmus/student_photos/' . rawurlencode($existingStudentPhoto))
    : '';
$existingUniPhoto = ($currentUniversity !== null) ? trim((string)($currentUniversity['photo'] ?? '')) : '';
$existingUniPhotoUrl = ($existingUniPhoto !== '')
    ? siteUrl('/data/erasmus/university_photos/' . rawurlencode($existingUniPhoto))
    : '';

$allCountries = [];
foreach (($store['universities'] ?? []) as $u) {
    if (!is_array($u)) {
        continue;
    }
    $country = erasmusCleanText((string)($u['country'] ?? ''));
    if ($country !== '') {
        $allCountries[$country] = true;
    }
}
$allCountries = array_keys($allCountries);
sort($allCountries, SORT_NATURAL | SORT_FLAG_CASE);

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>ErasmusMe Portal</title>
    <link rel="stylesheet" href="<?= h(siteUrl('/private/admin/css/admin.css')) ?>" />
    <link rel="stylesheet" href="<?= h(siteUrl('/private/erasmus/erasmus.css')) ?>" />
</head>
<body>
<div class="container erasmusWrap">
    <div class="header">
        <div class="brand">
            <div class="logo" aria-hidden="true"></div>
            <div>
                <h1 class="title">ErasmusMe</h1>
                <p class="subtitle">Partilha a tua experiência Erasmus com o NEB</p>
            </div>
        </div>

        <div class="nav">
            <a class="button" href="<?= h(siteUrl('/private/index.php')) ?>">Portal</a>
            <a class="button danger" href="<?= h(siteUrl('/private/logout.php')) ?>">Logout</a>
        </div>
    </div>

    <?php if (is_string($flashOk) && $flashOk !== ''): ?>
        <div class="alert ok"><?= h($flashOk) ?></div>
    <?php endif; ?>
    <?php if (is_string($flashErr) && $flashErr !== ''): ?>
        <div class="alert err"><?= h($flashErr) ?></div>
    <?php endif; ?>

    <div class="grid">
        <div class="card erasmusWelcome">
            <p class="welcomeBadge">ErasmusMe</p>
            <h2>Olá, <?= h($firstName) ?>. Vamos contar a tua aventura?</h2>
            <p>
                Este espaço foi feito para seres tu a inspirar os próximos colegas. Em poucos minutos,
                partilhas a tua experiência e ajudas quem está a preparar o próximo Erasmus.
            </p>

            <div class="welcomeSteps" role="list" aria-label="Passos para submeter a história Erasmus">
                <div class="welcomeStep" role="listitem">
                    <span class="stepIndex">1</span>
                    <div class="stepText">Escolhe o país e a universidade.</div>
                </div>
                <div class="welcomeStep" role="listitem">
                    <span class="stepIndex">2</span>
                    <div class="stepText">Adiciona a tua foto e, se quiseres, uma foto da universidade.</div>
                </div>
                <div class="welcomeStep" role="listitem">
                    <span class="stepIndex">3</span>
                    <div class="stepText">Escreve a tua história e envia para revisão.</div>
                </div>
            </div>
        </div>

        <div class="card half erasmusIntro">
            <h2>Guia rápido</h2>
            <p>
                Cada estudante pode manter <strong>uma história Erasmus</strong>. Sempre que atualizares,
                a submissão volta a <strong>pendente</strong> para revisão da equipa.
            </p>
            <ul>
                <li>Foto tua: obrigatória, até 2MB.</li>
                <li>História: mínimo de 30 caracteres, máximo de 9000.</li>
                <li>Universidade: fazemos associação automática quando existir uma semelhante.</li>
                <li>Foto da universidade: opcional, útil para novas universidades.</li>
            </ul>
        </div>

        <div class="card half erasmusStatus">
            <h2>Estado da tua submissão</h2>
            <?php if ($currentStory === null): ?>
                <div class="statusEmpty">
                    <strong>Ainda não tens uma história submetida.</strong>
                    <span>Preenche o formulário abaixo para começar.</span>
                </div>
            <?php else: ?>
                <?php $status = erasmusNormalizeStatus((string)($currentStory['status'] ?? 'pending')); ?>
                <div class="statusLine">
                    <span class="pill status-<?= h($status) ?>"><?= h(erasmusStatusLabel($status)) ?></span>
                    <span class="subtitle">Atualizado em <?= h(date('Y-m-d H:i', (int)($currentStory['updated_at'] ?? 0))) ?></span>
                </div>

                <div class="storyMeta">
                    <div><strong>Universidade:</strong> <?= h((string)($currentUniversity['name'] ?? '—')) ?></div>
                    <div><strong>País:</strong> <?= h((string)($currentUniversity['country'] ?? '—')) ?></div>
                </div>

                <div class="storySummary">
                    <?= h((string)($currentStory['story_summary'] ?? erasmusStorySummary((string)($currentStory['story_text'] ?? '')))) ?>
                </div>

                <?php $adminNote = trim((string)($currentStory['admin_note'] ?? '')); ?>
                <?php if ($adminNote !== ''): ?>
                    <div class="adminNote">
                        <div class="adminNoteLabel">Comentário da revisão</div>
                        <div><?= h($adminNote) ?></div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="card erasmusFormCard">
            <h2><?= $currentStory === null ? 'Partilhar nova história' : 'Atualizar a tua história' ?></h2>
            <p class="formLead">Quanto mais pessoal for o teu relato, mais útil será para quem vai de Erasmus a seguir.</p>

            <form class="erasmusForm" method="post" action="<?= h(siteUrl('/private/erasmus/index.php')) ?>" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />

                <div class="row2">
                    <label class="field">
                        <span class="label">País</span>
                        <input type="text" name="country" maxlength="120" list="countryList" value="<?= h($countryValue) ?>" required />
                    </label>

                    <label class="field">
                        <span class="label">Universidade</span>
                        <input type="text" name="university_name" maxlength="180" value="<?= h($universityValue) ?>" required />
                        <span class="help">Se existir uma universidade semelhante no mesmo país, ela será usada automaticamente.</span>
                    </label>
                </div>

                <datalist id="countryList">
                    <?php foreach ($allCountries as $country): ?>
                        <option value="<?= h((string)$country) ?>"></option>
                    <?php endforeach; ?>
                </datalist>

                <div class="row2">
                    <label class="field fileField">
                        <span class="label">Foto tua (obrigatória, máx. 2MB)</span>
                        <input id="studentPhotoInput" type="file" name="student_photo" accept="image/*" <?= $currentStory === null ? 'required' : '' ?> />
                        <span class="help">Usa uma foto em que estejas bem visível.</span>

                        <div class="previewShell">
                            <img
                                id="studentPhotoPreview"
                                class="currentPreview currentPreviewStudent<?= $existingStudentPhotoUrl === '' ? ' is-hidden' : '' ?>"
                                src="<?= h($existingStudentPhotoUrl) ?>"
                                alt="Pré-visualização da tua foto"
                            />
                            <div id="studentPhotoPlaceholder" class="previewPlaceholder<?= $existingStudentPhotoUrl !== '' ? ' is-hidden' : '' ?>">
                                Ainda não tens foto pessoal carregada.
                            </div>
                        </div>
                    </label>

                    <label class="field fileField">
                        <span class="label">Foto da universidade (opcional, máx. 2MB)</span>
                        <input id="uniPhotoInput" type="file" name="university_photo" accept="image/*" />
                        <span class="help">Ajuda a tornar o destino mais fácil de reconhecer na página pública.</span>

                        <div class="previewShell">
                            <img
                                id="uniPhotoPreview"
                                class="currentPreview currentPreviewUniversity<?= $existingUniPhotoUrl === '' ? ' is-hidden' : '' ?>"
                                src="<?= h($existingUniPhotoUrl) ?>"
                                alt="Pré-visualização da foto da universidade"
                            />
                            <div id="uniPhotoPlaceholder" class="previewPlaceholder<?= $existingUniPhotoUrl !== '' ? ' is-hidden' : '' ?>">
                                Sem foto da universidade associada.
                            </div>
                        </div>
                    </label>
                </div>

                <label class="field">
                    <div class="textareaHead">
                        <span class="label">A tua história Erasmus</span>
                        <span class="counter"><strong id="storyChars"><?= h((string)$initialStoryLen) ?></strong>/9000</span>
                    </div>
                    <textarea id="storyText" name="story_text" maxlength="9000" rows="14" required><?= h($storyValue) ?></textarea>
                    <span class="help">Sugestão: fala sobre aulas, alojamento, custos, integração e o que mais te marcou.</span>
                </label>

                <div class="actions">
                    <button class="button primary" type="submit"><?= $currentStory === null ? 'Enviar história' : 'Guardar e reenviar para revisão' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
(function () {
    var storyText = document.getElementById('storyText');
    var storyChars = document.getElementById('storyChars');

    if (storyText && storyChars) {
        var updateCount = function () {
            storyChars.textContent = String(storyText.value.length);
        };

        storyText.addEventListener('input', updateCount);
        updateCount();
    }

    function bindImagePreview(inputId, previewId, placeholderId) {
        var input = document.getElementById(inputId);
        var preview = document.getElementById(previewId);
        var placeholder = document.getElementById(placeholderId);

        if (!input || !preview || !placeholder) {
            return;
        }

        input.addEventListener('change', function () {
            var file = input.files && input.files[0] ? input.files[0] : null;
            if (!file) {
                return;
            }

            var localUrl = URL.createObjectURL(file);
            preview.src = localUrl;
            preview.classList.remove('is-hidden');
            placeholder.classList.add('is-hidden');
        });
    }

    bindImagePreview('studentPhotoInput', 'studentPhotoPreview', 'studentPhotoPlaceholder');
    bindImagePreview('uniPhotoInput', 'uniPhotoPreview', 'uniPhotoPlaceholder');
})();
</script>
</body>
</html>
