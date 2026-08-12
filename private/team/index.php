<?php
declare(strict_types=1);

require_once(__DIR__ . '/session.php');
require_once(__DIR__ . '/../admin/private/teams.php');

function detectMime(string $path): string
{
    if (class_exists('finfo')) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        return (string)$fi->file($path);
    }
    return '';
}

function extensionForMime(string $mime): string
{
  switch ($mime) {
    case 'image/jpeg': return 'jpg';
    case 'image/png': return 'png';
    case 'image/gif': return 'gif';
    case 'image/webp': return 'webp';
    default: return 'bin';
  }
}

function myUser(): array
{
  if (is_array($_SESSION['user'] ?? null)) {
    return $_SESSION['user'];
  }
  return (is_array($_SESSION['team_user'] ?? null)) ? $_SESSION['team_user'] : [];
}

function listMyDepartments(string $istid): array
{
    $istid = strtolower(trim($istid));
    $out = [];
    foreach (listDepartments() as $d) {
        $slug = (string)($d['slug'] ?? '');
        if ($slug === '') continue;
        if (is_file(deptPeoplePath($slug, $istid))) $out[] = $slug;
    }
    return $out;
}

function isPresidentDirecao(string $istid): bool
{
    $p = deptPeoplePath('presidency/direcao', $istid);
    if (!is_file($p)) return false;
    $row = loadJsonFile($p);
    return (string)($row['role'] ?? '') === 'Presidente';
}

function shorthandToBytes(string $value): int
{
  $value = trim(strtolower($value));
  if ($value === '') {
    return 0;
  }

  $unit = substr($value, -1);
  $number = (float)$value;

  switch ($unit) {
    case 'g': return (int)($number * 1024 * 1024 * 1024);
    case 'm': return (int)($number * 1024 * 1024);
    case 'k': return (int)($number * 1024);
    default: return (int)$number;
  }
}

function teamUploadLimitError(): string
{
  $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
  $postMax = shorthandToBytes((string)ini_get('post_max_size'));
  $uploadMax = shorthandToBytes((string)ini_get('upload_max_filesize'));

  if ($contentLength > 0 && $postMax > 0 && $contentLength > $postMax) {
    return 'The picture is too large for the server limit (2MB). Please upload a smaller image and try again.';
  }

  if ($uploadMax > 0 && isset($_FILES['photo']) && is_array($_FILES['photo'])) {
    $err = (int)($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE) {
      return 'The picture is too large for the upload limit. Please upload a smaller image and try again.';
    }
  }

  return '';
}

$u = myUser();
$logged = ($u['istid'] ?? '') !== '';
$flashOk = $_SESSION['flash_ok'] ?? null;
$flashErr = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

$csrf = ensureTeamCsrf();
$maxBytes = 2 * 1024 * 1024;
$allowedImg = ['image/jpeg','image/png','image/gif','image/webp'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireTeamLogin();

    try {
        $uploadError = teamUploadLimitError();
        if ($uploadError !== '') {
            throw new RuntimeException($uploadError);
        }

        verifyTeamCsrf((string)($_POST['csrf'] ?? ''));

        $action = (string)($_POST['action'] ?? '');
        $dept = (string)($_POST['dept'] ?? '');
        $istid = strtolower(trim((string)($u['istid'] ?? '')));

        if (!isValidDeptSlug($dept)) throw new InvalidArgumentException('Invalid department.');
        if (!is_file(deptPeoplePath($dept, $istid))) throw new RuntimeException('Not in this team.');

        if ($action === 'save_profile') {
            $linkedin = trim((string)($_POST['linkedinUrl'] ?? ''));
            $cv = trim((string)($_POST['cvUrl'] ?? ''));

            if ($linkedin !== '' && !preg_match('~^https?://~i', $linkedin)) {
                throw new InvalidArgumentException('LinkedIn link must be a URL.');
            }
            if ($cv !== '' && !preg_match('~^https?://~i', $cv)) {
                throw new InvalidArgumentException('CV link must be a URL.');
            }

            $patch = [
                'linkedinUrl' => $linkedin,
                'cvUrl' => $cv,
            ];

            if (isset($_POST['photo_remove']) && $_POST['photo_remove'] === '1') {
                foreach (glob(deptPeoplePhotosDir($dept) . '/' . $istid . '.*') ?: [] as $f) {
                    @unlink($f);
                }
                $patch['photo'] = '';
            } elseif (isset($_FILES['photo']) && is_array($_FILES['photo'])) {
                $err = (int)($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($err !== UPLOAD_ERR_NO_FILE) {
                    if ($err !== UPLOAD_ERR_OK) throw new RuntimeException('Upload error: ' . $err);
                    $size = (int)($_FILES['photo']['size'] ?? 0);
                    if ($size <= 0 || $size > $maxBytes) throw new RuntimeException('Invalid photo size.');

                    $tmp = (string)($_FILES['photo']['tmp_name'] ?? '');
                    if ($tmp === '' || !is_file($tmp)) throw new RuntimeException('Missing upload tmp file.');

                    $mime = detectMime($tmp);
                    if (!in_array($mime, $allowedImg, true)) throw new RuntimeException('Invalid image type.');
                    $ext = extensionForMime($mime);

                    ensureDir(deptPeoplePhotosDir($dept));
                    $destName = $istid . '.' . $ext;
                    $destPath = deptPeoplePhotosDir($dept) . '/' . $destName;
                    if (!move_uploaded_file($tmp, $destPath)) throw new RuntimeException('Failed to store photo.');

                    $patch['photo'] = 'people/photos/' . $destName;
                }
            }

            updateUserInDepartment($dept, $istid, $patch);
            $_SESSION['flash_ok'] = 'Guardado.';
        } elseif ($action === 'save_president_message') {
            if ($dept !== 'presidency/direcao' || !isPresidentDirecao($istid)) {
                throw new RuntimeException('Not allowed.');
            }
            $msg = trim((string)($_POST['presidentMessage'] ?? ''));
            if (mb_strlen($msg) > 3000) throw new InvalidArgumentException('Message too long.');

            $dj = deptJsonPath('presidency/direcao');
            $cur = loadJsonFile($dj);
            $cur['presidentMessage'] = $msg;
            $cur['updated_at'] = time();
            saveJsonFile($dj, $cur);

            $_SESSION['flash_ok'] = 'Mensagem atualizada.';
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }

    header('Location: ' . siteUrl('/private/team/index.php'));
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Portal do Membro</title>
  <link rel="stylesheet" href="<?= h(siteUrl('/private/team/teams.css')) ?>" />
</head>
<body>
<div class="wrap">
  <header class="top">
    <div>
      <h1>Portal do Membro</h1>
      <div class="sub">Faz Login com Fénix para seres adicionado e gerires a tua informação.</div>
    </div>
    <div class="actions">
      <a class="btn" href="<?= h(siteUrl('/private/index.php')) ?>">Portal</a>
      <?php if (!$logged): ?>
        <a class="btn primary" href="<?= h(siteUrl('/private/login.php')) ?>">Login com Fénix</a>
      <?php else: ?>
        <div class="me"><?= h((string)$u['name']) ?> (<code><?= h((string)$u['istid']) ?></code>)</div>
        <a class="btn" href="<?= h(siteUrl('/private/team/logout.php')) ?>">Logout</a>
      <?php endif; ?>
    </div>
  </header>

  <?php if (is_string($flashOk) && $flashOk !== ''): ?><div class="alert ok"><?= h($flashOk) ?></div><?php endif; ?>
  <?php if (is_string($flashErr) && $flashErr !== ''): ?><div class="alert err"><?= h($flashErr) ?></div><?php endif; ?>

  <?php if (!$logged): ?>
    <div class="card">
      <p>Faz login para continuares.</p>
    </div>
  <?php else: ?>
    <?php
      $istid = (string)$u['istid'];
      $myDepts = listMyDepartments($istid);
    ?>
    <?php if (empty($myDepts)): ?>
      <div class="card">
        <strong>Ainda não estás numa equipa, espera até seres adicionado</strong>
        <div class="sub" style="margin-top:6px">Um gestor do site irá procurar-te na lista de utilizadores em espera.</div>
      </div>
    <?php else: ?>
      <?php foreach ($myDepts as $slug): ?>
        <?php
          $dept = loadJsonFile(deptJsonPath($slug));
          $deptName = (string)($dept['name'] ?? $slug);
          $row = loadJsonFile(deptPeoplePath($slug, $istid));
          $photo = (string)($row['photo'] ?? '');
          $linkedin = (string)($row['linkedinUrl'] ?? '');
          $cv = (string)($row['cvUrl'] ?? '');
          $role = (string)($row['role'] ?? '');

            $photoUrl = ($photo !== '')
              ? siteUrl('/data/teams/' . $slug . '/' . $photo)
              : siteUrl('/private/team/default-person.svg');
        ?>
        <div class="card">
          <h2><?= h($deptName) ?> <span class="sub"><code><?= h($slug) ?></code></span></h2>
          <?php if ($role !== ''): ?><div class="pill"><?= h($role) ?></div><?php endif; ?>

          <div class="row">
            <div class="media">
              <a href="<?= h($linkedin !== '' ? $linkedin : '#') ?>" <?= $linkedin !== '' ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                <img src="<?= h($photoUrl) ?>" alt="Foto de <?= h((string)$u['name']) ?>" />
              </a>
              <div class="hint">Clicar na foto abre o LinkedIn (se definido).</div>
            </div>

            <form method="post" action="<?= h(siteUrl('/private/team/index.php')) ?>" enctype="multipart/form-data" class="form">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
              <input type="hidden" name="action" value="save_profile" />
              <input type="hidden" name="dept" value="<?= h($slug) ?>" />

              <label>
                <span>Foto (opcional)</span>
                <input type="file" name="photo" accept="image/*" />
              </label>
              <label class="inline">
                <input type="checkbox" name="photo_remove" value="1" />
                <span>Remover foto (volta ao default)</span>
              </label>

              <label>
                <span>LinkedIn URL (opcional)</span>
                <input type="url" name="linkedinUrl" value="<?= h($linkedin) ?>" placeholder="https://www.linkedin.com/in/..." />
              </label>

              <label>
                <span>CV URL (opcional)</span>
                <input type="url" name="cvUrl" value="<?= h($cv) ?>" placeholder="https://..." />
              </label>

              <button class="btn primary" type="submit">Guardar</button>
            </form>
          </div>

          <?php if ($slug === 'presidency/direcao' && isPresidentDirecao($istid)): ?>
            <hr style="opacity:.2;margin:16px 0" />
            <h3>Mensagem do Presidente</h3>
            <form method="post" action="<?= h(siteUrl('/private/team/index.php')) ?>" class="form">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
              <input type="hidden" name="action" value="save_president_message" />
              <input type="hidden" name="dept" value="presidency/direcao" />
              <textarea name="presidentMessage" rows="5"><?= h((string)($dept['presidentMessage'] ?? '')) ?></textarea>
              <button class="btn primary" type="submit">Guardar mensagem</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
