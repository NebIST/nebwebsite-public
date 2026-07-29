<?php
declare(strict_types=1);

require_once(__DIR__ . '/../auth.php');
require_once(__DIR__ . '/../private/roles.php');
require_once(__DIR__ . '/../private/logs.php');
require_once(__DIR__ . '/../private/user.php');
require_once(__DIR__ . '/../private/teams.php');

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$currentUser = (string)($_SESSION['user']['istid'] ?? '');
$canManage = ($currentUser !== '') && (isAdmin($currentUser) || isSiteManager($currentUser));
if (!$canManage) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Not authorized</title></head><body>';
    echo '<h1>Not authorized</h1>';
    echo '</body></html>';
    exit;
}

if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

ensureMandatoryOrgaosSociais();

$flashOk = $_SESSION['flash_ok'] ?? null;
$flashErr = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

$maxBytes = 2 * 1024 * 1024;
$allowedImg = ['image/jpeg','image/png','image/gif','image/webp'];

function detectMime(string $path): string {
    if (class_exists('finfo')) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        return (string)$fi->file($path);
    }
    return '';
}
function extensionForMime(string $mime): string {
    switch ($mime) {
        case 'image/jpeg': return 'jpg';
        case 'image/png': return 'png';
        case 'image/gif': return 'gif';
        case 'image/webp': return 'webp';
        default: return 'bin';
    }
}

function readAllUsers(): array
{
    // Uses admin/private/user.php storage.
    // IMPORTANT: user.store.json is the schema; user.data.json is the actual persisted user info.
    return loadUserData();
}

function roleOptionsForDept(string $deptSlug): ?array
{
    // Only enforce dropdown UX for the Órgãos Sociais.
    // Keep backend permissive (still accepts any string) in case data already exists.
    switch ($deptSlug) {
        case 'presidency/direcao':
            return ['', 'Presidente', 'Secretário', 'Tesoureiro', 'Vogal'];
        case 'presidency/assembleia-geral':
            return ['', 'Presidente da Mesa', 'Suplente da Mesa', '1º Secretário','2º Secretário', 'Vogal'];
        case 'presidency/conselho-fiscal':
            return ['', 'Efetiva', 'Vogal'];
        default:
            return null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    if (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) {
        $_SESSION['flash_error'] = 'Invalid CSRF token. Please retry.';
        header('Location: ' . adminUrl('/private/admin/teams/index.php'));
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'dept_create') {
            $name = trim((string)($_POST['dept_name'] ?? ''));
            if ($name === '') throw new InvalidArgumentException('Department name is required.');

            $slug = trim((string)($_POST['dept_slug'] ?? ''));
            $slug = $slug !== '' ? $slug : slugify($name);
            if (!isValidDeptSlug($slug)) throw new InvalidArgumentException('Invalid slug.');

            ensureDir(deptDir($slug));
            ensureDir(deptPeopleDir($slug));
            ensureDir(deptPhotosDir($slug));
            ensureDir(deptPeoplePhotosDir($slug));

            $p = deptJsonPath($slug);
            if (is_file($p)) throw new RuntimeException('Department already exists.');

            $now = time();
            saveJsonFile($p, [
                'slug' => $slug,
                'name' => $name,
                'description' => '',
                'photo' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            saveLog($currentUser, 'teams_department_create', ['slug' => $slug, 'name' => $name]);
            $_SESSION['flash_ok'] = 'Department created.';
        } elseif ($action === 'dept_update') {
            $slug = (string)($_POST['dept_slug'] ?? '');
            if (!isValidDeptSlug($slug)) throw new InvalidArgumentException('Invalid slug.');

            $name = trim((string)($_POST['dept_name'] ?? ''));
            $desc = trim((string)($_POST['dept_description'] ?? ''));
            if ($name === '') throw new InvalidArgumentException('Name is required.');
            if (mb_strlen($desc) > 512) throw new InvalidArgumentException('Description too long (max 512).');

            $dept = loadJsonFile(deptJsonPath($slug));

            // photo optional
            $photoName = (string)($dept['photo'] ?? '');
            if (isset($_POST['dept_photo_remove']) && $_POST['dept_photo_remove'] === '1') {
                if ($photoName !== '') {
                    $old = deptPhotosDir($slug) . '/' . $photoName;
                    if (is_file($old)) @unlink($old);
                }
                $photoName = '';
            } elseif (isset($_FILES['dept_photo']) && is_array($_FILES['dept_photo'])) {
                $err = (int)($_FILES['dept_photo']['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($err !== UPLOAD_ERR_NO_FILE) {
                    if ($err !== UPLOAD_ERR_OK) throw new RuntimeException('Upload error: ' . $err);
                    $size = (int)($_FILES['dept_photo']['size'] ?? 0);
                    if ($size <= 0 || $size > $maxBytes) throw new RuntimeException('Invalid photo size.');

                    $tmp = (string)($_FILES['dept_photo']['tmp_name'] ?? '');
                    if ($tmp === '' || !is_file($tmp)) throw new RuntimeException('Missing upload tmp file.');

                    $mime = detectMime($tmp);
                    if (!in_array($mime, $allowedImg, true)) throw new RuntimeException('Invalid image type.');
                    $ext = extensionForMime($mime);

                    ensureDir(deptPhotosDir($slug));
                    $destName = 'dept.' . $ext;
                    $destPath = deptPhotosDir($slug) . '/' . $destName;
                    if (!move_uploaded_file($tmp, $destPath)) throw new RuntimeException('Failed to store photo.');
                    $photoName = $destName;
                }
            }

            setDepartmentMeta($slug, $name, $desc, $photoName);

            saveLog($currentUser, 'teams_department_update', ['slug' => $slug]);
            $_SESSION['flash_ok'] = 'Department updated.';
        } elseif ($action === 'dept_delete') {
            $slug = (string)($_POST['dept_slug'] ?? '');
            if (!isValidDeptSlug($slug)) throw new InvalidArgumentException('Invalid slug.');

            deleteDepartment($slug);

            saveLog($currentUser, 'teams_department_delete', ['slug' => $slug]);
            $_SESSION['flash_ok'] = 'Department deleted.';
        } elseif ($action === 'member_add') {
            $dept = (string)($_POST['dept_slug'] ?? '');
            $istid = (string)($_POST['istid'] ?? '');
            if (!isValidDeptSlug($dept)) throw new InvalidArgumentException('Invalid department.');
            $u = getUser($istid);
            if (!is_array($u) || (string)($u['istid'] ?? '') === '') {
                throw new RuntimeException('User not found (must login first).');
            }

            addUserToDepartment($dept, $u);
            saveLog($currentUser, 'teams_member_add', ['dept' => $dept, 'istid' => $istid]);
            $_SESSION['flash_ok'] = 'Member added.';
        } elseif ($action === 'member_remove') {
            $dept = (string)($_POST['dept_slug'] ?? '');
            $istid = (string)($_POST['istid'] ?? '');
            if (!isValidDeptSlug($dept)) throw new InvalidArgumentException('Invalid department.');

            removeUserFromDepartment($dept, $istid);
            saveLog($currentUser, 'teams_member_remove', ['dept' => $dept, 'istid' => $istid]);
            $_SESSION['flash_ok'] = 'Member removed.';
        } elseif ($action === 'member_role_set') {
            $dept = (string)($_POST['dept_slug'] ?? '');
            $istid = (string)($_POST['istid'] ?? '');
            $role = trim((string)($_POST['role'] ?? ''));
            if (!isValidDeptSlug($dept)) throw new InvalidArgumentException('Invalid department.');

            // role optional; allow empty
            if (mb_strlen($role) > 64) throw new InvalidArgumentException('Role too long.');

            updateUserInDepartment($dept, strtolower($istid), ['role' => $role]);
            saveLog($currentUser, 'teams_member_role_set', ['dept' => $dept, 'istid' => $istid, 'role' => $role]);
            $_SESSION['flash_ok'] = 'Role updated.';
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }

    header('Location: ' . adminUrl('/private/admin/teams/index.php'));
    exit;
}

$departments = listDepartments();
$membershipIndex = loadMembershipIndex();

// waiting users: saved users not in any team
$allUsers = readAllUsers();
$waiting = [];
foreach ($allUsers as $istid => $u) {
    if (!is_array($u)) continue;
    $id = (string)($u['istid'] ?? $istid);
    $id = strtolower(trim($id));
    if ($id === '') continue;
    if (!isset($membershipIndex[$id])) $waiting[] = $u;
}
usort($waiting, function ($a, $b) {
    return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
});

$waitingSearch = trim((string)($_GET['wait_search'] ?? ''));
$waitingPage = max(1, (int)($_GET['wait_page'] ?? 1));
$waitingPerPage = 80;

if ($waitingSearch !== '') {
    $needle = strtolower($waitingSearch);
    $filtered = [];
    foreach ($waiting as $u) {
        $id = strtolower((string)($u['istid'] ?? ''));
        $name = strtolower((string)($u['name'] ?? ''));
        $email = strtolower((string)($u['email'] ?? ''));
        if ($id === '' && $name === '' && $email === '') {
            continue;
        }
        if (str_contains($id, $needle) || str_contains($name, $needle) || str_contains($email, $needle)) {
            $filtered[] = $u;
        }
    }
    $waiting = $filtered;
}

$waitingTotal = count($waiting);
$waitingPages = max(1, (int)ceil($waitingTotal / $waitingPerPage));
$waitingPage = min($waitingPage, $waitingPages);
$waitingOffset = ($waitingPage - 1) * $waitingPerPage;
$waiting = array_slice($waiting, $waitingOffset, $waitingPerPage);

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8" />
    <style>
        dialog {
            background: #fffdf8;
            color: #1f2937;
            border: 1px solid #d8d0c0;
            border-radius: 14px;
            padding: 0;
            box-shadow: 0 24px 70px rgba(0, 0, 0, 0.28);
        }
        dialog::backdrop {
            background: rgba(17, 24, 39, 0.72);
            backdrop-filter: blur(2px);
        }
        dialog .tableWrap {
            background: #ffffff;
            border: 1px solid #ebdfc8;
            border-radius: 10px;
        }
        dialog .table th {
            background: #f5efe4;
            color: #243041;
        }
        dialog .subtitle {
            color: #4b5563;
        }
        .deptBox {
            background: #fcfaf5;
            border: 1px solid #e7dcc8;
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 12px;
        }
        .deptBox summary {
            cursor: pointer;
            color: #1f2937;
            padding: 4px 0;
        }
        .deptBox summary:hover {
            color: #0f766e;
        }
    </style>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Teams — Admin</title>
    <link rel="stylesheet" href="<?= h(adminUrl('/private/admin/css/admin.css')) ?>" />
    <link rel="stylesheet" href="<?= h(adminUrl('/private/admin/teams/teams.css')) ?>" />
</head>
<body>
<div class="container">
    <div class="header">
        <div class="brand">
            <img class="loginLogo" style="width:150px;height:auto;" src="<?= h(adminUrl('/private/admin/images/logocorhorizontal-2.png')) ?>" alt="NEB" />
            <div>
                <h1 class="title">Teams</h1>
                <p class="subtitle">Departamentos + Órgãos Sociais</p>
            </div>
        </div>
        <div class="nav">
            <a class="button" href="<?= h(adminUrl('/private/admin/index.php')) ?>">Página Admin</a>
            <a class="button" href="<?= h(siteUrl('/team/index.php')) ?>" target="_blank" rel="noopener noreferrer">Portal do Membro</a>
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
            <h2>Criar departamento</h2>
            <form method="post" action="<?= h(adminUrl('/private/admin/teams/index.php')) ?>" class="uploadForm">
                <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                <input type="hidden" name="action" value="dept_create" />

                <div class="row">
                    <label class="field">
                        <span class="label">Nome</span>
                        <input type="text" name="dept_name" maxlength="80" required />
                    </label>
                    <label class="field">
                        <span class="label">Slug (opcional)</span>
                        <input type="text" name="dept_slug" maxlength="80" placeholder="ex: marketing" />
                        <span class="help">Se vazio, é gerado automaticamente.</span>
                    </label>
                </div>

                <div class="row actions">
                    <button class="button primary" type="submit">Criar</button>
                </div>
            </form>
        </div>

        <div class="card half">
            <h2>Utilizadores à espera <span class="subtitle">(<?= (int)$waitingTotal ?>)</span></h2>
            <p class="subtitle" style="margin-top:0">Estes utilizadores fizeram login no Portal do Membro, mas ainda não foram adicionados a nenhuma equipa.</p>

            <div class="uniActions" style="margin-bottom:10px">
                <button class="button primary" type="button" id="openWaitingUsersDialog">Abrir lista de pessoas</button>
            </div>

            <dialog id="waitingUsersDialog" class="waitingUsersDialog">
                <div class="waitingUsersDialogBody">
                    <div class="waitingUsersDialogHeader">
                        <h3>Utilizadores à espera</h3>
                        <button class="button" type="button" id="closeWaitingUsersDialog">Fechar</button>
                    </div>

                    <form method="get" action="<?= h(adminUrl('/private/admin/teams/index.php')) ?>" class="uploadForm" style="margin:0">
                        <label class="field" style="margin:0">
                            <span class="label">Pesquisar</span>
                            <input type="search" name="wait_search" value="<?= h($waitingSearch) ?>" placeholder="Nome, ISTID, email..." autocomplete="off" />
                        </label>
                        <div class="row actions" style="margin-top:10px">
                            <button class="button primary" type="submit">Pesquisar</button>
                        </div>
                    </form>

                    <div class="subtitle">Mostrando <?= (int)count($waiting) ?> de <?= (int)$waitingTotal ?> resultados. Para grandes volumes, a pesquisa é feita no servidor.</div>

                    <div class="tableWrap" style="margin-top:2px">
                        <table class="table">
                            <thead>
                            <tr><th>ISTID</th><th>Nome</th><th>Email</th></tr>
                            </thead>
                            <tbody>
                            <?php if (empty($waiting)): ?>
                                <tr><td colspan="3"><span class="subtitle">Sem utilizadores correspondentes.</span></td></tr>
                            <?php else: ?>
                                <?php foreach ($waiting as $u): ?>
                                    <?php
                                        $id = (string)($u['istid'] ?? '');
                                        $nm = (string)($u['name'] ?? '');
                                        $em = (string)($u['email'] ?? '');
                                    ?>
                                    <tr>
                                        <td><code><?= h($id) ?></code></td>
                                        <td><?= h($nm) ?></td>
                                        <td><?= h($em) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($waitingPages > 1): ?>
                        <div class="row actions" style="justify-content:space-between; margin-top:4px;">
                            <?php if ($waitingPage > 1): ?>
                                <a class="button" href="<?= h(adminUrl('/private/admin/teams/index.php?wait_search=' . rawurlencode($waitingSearch) . '&wait_page=' . ($waitingPage - 1))) ?>">Anterior</a>
                            <?php else: ?>
                                <span></span>
                            <?php endif; ?>
                            <span class="subtitle">Página <?= (int)$waitingPage ?> / <?= (int)$waitingPages ?></span>
                            <?php if ($waitingPage < $waitingPages): ?>
                                <a class="button" href="<?= h(adminUrl('/private/admin/teams/index.php?wait_search=' . rawurlencode($waitingSearch) . '&wait_page=' . ($waitingPage + 1))) ?>">Seguinte</a>
                            <?php else: ?>
                                <span></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </dialog>
        </div>

        <div class="card" style="grid-column: span 12;">
            <h2>Departamentos</h2>
            <div class="subtitle" style="margin-bottom:10px">Pode esconder qualquer departamento fechando o bloco correspondente. O conteúdo fica mais compactado e mais fácil de navegar.</div>
            <div class="teamSetupChecklist">
                <h3>A página "Sobre Nós" só irá mostrar as equipas e pessoas se:</h3>
                <ul>
                    <li>A Direção tiver: Presidente, Tesoureiro, Secretário e pelo menos um Vogal.</li>
                    <li>A Assembleia Geral tiver: Presidente da Mesa, Suplente da Mesa, 1º Secretário, 2º Secretário e pelo menos um Vogal.</li>
                    <li>O Conselho Fiscal tiver: pelo menos uma pessoa com o cargo Efetiva e pelo menos uma pessoa com o cargo Vogal.</li>
                    <li>Cada pessoa tiver um istid e um name para aparecer na lista pública.</li>
                </ul>
            </div>
            <?php if (empty($departments)): ?>
                <div class="subtitle">Nenhum departamento encontrado.</div>
            <?php else: ?>
                <?php foreach ($departments as $d): ?>
                    <?php
                        $slug = (string)($d['slug'] ?? '');
                        $name = (string)($d['name'] ?? $slug);
                        $desc = (string)($d['description'] ?? '');
                        $photo = (string)($d['photo'] ?? '');

                        $people = [];
                        $pdir = deptPeopleDir($slug);
                        if (is_dir($pdir)) {
                            foreach (glob($pdir . '/*.json') ?: [] as $pf) {
                                $row = loadJsonFile($pf);
                                if (is_array($row) && (string)($row['istid'] ?? '') !== '') $people[] = $row;
                            }
                        }
                        usort($people, function ($a, $b) {
                            return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
                        });
                    ?>

                    <?php
                        $isProtectedDept = in_array($slug, [
                            'presidency/direcao',
                            'presidency/assembleia-geral',
                            'presidency/conselho-fiscal',
                        ], true);
                        $deleteConfirmMessage = $isProtectedDept
                            ? 'Apenas serão removidos os membros deste departamento; o departamento não pode ser removido.'
                            : 'Eliminar o departamento "' . $name . '"? Isto elimina todos os membros e fotos dentro do departamento.';
                    ?>
                    <details class="deptBox">
                        <summary>
                            <span style="font-weight:700"><?= h($name) ?></span>
                            <span class="subtitle" style="margin-left:10px"><code><?= h($slug) ?></code> • <?= (int)count($people) ?> membro(s)</span>
                            <span class="subtitle" style="margin-left:10px">Clique para abrir/fechar</span>
                        </summary>

                        <form method="post" action="<?= h(adminUrl('/private/admin/teams/index.php')) ?>" enctype="multipart/form-data" class="uploadForm" style="margin-top:10px">
                            <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                            <input type="hidden" name="action" value="dept_update" />
                            <input type="hidden" name="dept_slug" value="<?= h($slug) ?>" />

                            <label class="field">
                                <span class="label">Nome</span>
                                <input type="text" name="dept_name" maxlength="80" value="<?= h($name) ?>" required />
                            </label>

                            <label class="field">
                                <span class="label">Descrição (≤512)</span>
                                <textarea name="dept_description" maxlength="512" rows="3"><?= h($desc) ?></textarea>
                            </label>

                            <div class="row">
                                <label class="field">
                                    <span class="label">Foto do departamento (opcional)</span>
                                    <input type="file" name="dept_photo" accept="image/*" />
                                    <?php if ($photo !== ''): ?>
                                        <span class="help">Atual: <code><?= h($photo) ?></code></span>
                                        <label class="help" style="display:flex;align-items:center;gap:8px;margin-top:6px">
                                            <input type="checkbox" name="dept_photo_remove" value="1" />
                                            <span>Remover foto</span>
                                        </label>
                                    <?php endif; ?>
                                </label>
                            </div>

                            <div class="row actions">
                                <button class="button primary" type="submit">Guardar</button>
                            </div>
                        </form>

                        <form method="post" action="<?= h(adminUrl('/private/admin/teams/index.php')) ?>" style="margin-top:10px"
                              onsubmit="return confirm('<?= h($deleteConfirmMessage) ?>');">
                            <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                            <input type="hidden" name="action" value="dept_delete" />
                            <input type="hidden" name="dept_slug" value="<?= h($slug) ?>" />
                            <button class="button danger" type="submit">Eliminar departamento</button>
                        </form>

                        <h3>Membros</h3>
                        <?php if (empty($people)): ?>
                            <div class="subtitle">Sem membros.</div>
                        <?php else: ?>
                            <div class="tableWrap">
                                <table class="table">
                                    <thead><tr><th>ISTID</th><th>Nome</th><th>Role (opcional)</th><th>Remover</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($people as $p): ?>
                                        <?php
                                            $pid = (string)($p['istid'] ?? '');
                                            $pn = (string)($p['name'] ?? '');
                                            $role = (string)($p['role'] ?? '');
                                            $roleOptions = roleOptionsForDept($slug);
                                        ?>
                                        <tr>
                                            <td><code><?= h($pid) ?></code></td>
                                            <td><?= h($pn) ?></td>
                                            <td>
                                                <form method="post" action="<?= h(adminUrl('/private/admin/teams/index.php')) ?>" style="display:flex;gap:8px;align-items:center;margin:0">
                                                    <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                                                    <input type="hidden" name="action" value="member_role_set" />
                                                    <input type="hidden" name="dept_slug" value="<?= h($slug) ?>" />
                                                    <input type="hidden" name="istid" value="<?= h($pid) ?>" />
                                                    <?php if (is_array($roleOptions)): ?>
                                                        <select name="role">
                                                            <?php foreach ($roleOptions as $opt): ?>
                                                                <?php $optStr = (string)$opt; ?>
                                                                <option value="<?= h($optStr) ?>" <?= $optStr === $role ? 'selected' : '' ?>><?= $optStr === '' ? '—' : h($optStr) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    <?php else: ?>
                                                        <input type="text" name="role" value="<?= h($role) ?>" maxlength="64" placeholder="opcional" />
                                                    <?php endif; ?>
                                                    <button class="button" type="submit">OK</button>
                                                </form>
                                            </td>
                                            <td>
                                                <form method="post" action="<?= h(adminUrl('/private/admin/teams/index.php')) ?>" onsubmit="return confirm('Remover este membro do departamento?');" style="margin:0">
                                                    <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                                                    <input type="hidden" name="action" value="member_remove" />
                                                    <input type="hidden" name="dept_slug" value="<?= h($slug) ?>" />
                                                    <input type="hidden" name="istid" value="<?= h($pid) ?>" />
                                                    <button class="button danger" type="submit">Remover</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>

                        <h3>Adicionar membro</h3>
                        <form method="post" action="<?= h(adminUrl('/private/admin/teams/index.php')) ?>" class="uploadForm">
                            <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                            <input type="hidden" name="action" value="member_add" />
                            <input type="hidden" name="dept_slug" value="<?= h($slug) ?>" />
                            <label class="field">
                                <span class="label">ISTID (tem de ter feito login no Portal do Membro)</span>
                                <input type="text" name="istid" placeholder="istXXXXXXX" required />
                            </label>
                            <div class="row actions">
                                <button class="button primary" type="submit">Adicionar</button>
                            </div>
                        </form>
                    </details>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(() => {
  const dialog = document.getElementById('waitingUsersDialog');
  const openBtn = document.getElementById('openWaitingUsersDialog');
  const closeBtn = document.getElementById('closeWaitingUsersDialog');

  if (dialog && openBtn) {
    openBtn.addEventListener('click', () => {
      if (typeof dialog.showModal === 'function') {
        dialog.showModal();
      }
    });
  }

  if (dialog && closeBtn) {
    closeBtn.addEventListener('click', () => {
      dialog.close();
    });
  }

  if (dialog) {
    dialog.addEventListener('click', (ev) => {
      const target = ev.target;
      if (target === dialog) {
        dialog.close();
      }
    });
  }
})();
</script>
</body>
</html>