<?php
declare(strict_types=1);

require_once(__DIR__ . '/../auth.php');
require_once(__DIR__ . '/../private/roles.php');
require_once(__DIR__ . '/../private/user.php');

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$currentUser = (string)($_SESSION['user']['istid'] ?? '');
if ($currentUser === '' || !isAdmin($currentUser)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Not authorized</title></head><body>';
    echo '<h1>Not authorized</h1>';
    echo '<p>You are not allowed to access this page.</p>';
    echo '<p><button type="button" onclick="history.back()">Back</button> <a href="' . h(adminUrl('/private/admin/index.php')) . '">Admin</a></p>';
    echo '</body></html>';
    exit;
}

$roleDefs = getDefinedRoles();

if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    if (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) {
        $_SESSION['flash_error'] = 'Invalid CSRF token. Please retry.';
        header('Location: ' . adminUrl('/private/admin/control/index.php'));
        exit;
    }

    $action = (string)($_POST['action'] ?? '');
    $roleKey = (string)($_POST['role'] ?? '');
    $username = (string)($_POST['username'] ?? '');

    // Validate role key early (avoid weird keys)
    if (!isset($roleDefs[$roleKey])) {
        $_SESSION['flash_error'] = 'Unknown role.';
        header('Location: ' . adminUrl('/private/admin/control/index.php'));
        exit;
    }

    try {
        if ($action === 'add') {
            addUserToRole($username, $roleKey);
            saveLog($currentUser, 'add_role_member', ['addedUser' => $username, 'role' => $roleKey]);
            $_SESSION['flash_ok'] = 'User added.';
        } elseif ($action === 'remove') {
            // Extra guard: avoid accidental self-lockout
            if ($roleKey === 'admin' && normalizeUsername($username) === normalizeUsername($currentUser)) {
                throw new RuntimeException('Refusing to remove your own admin role from this panel.');
            }
            removeUserFromRole($username, $roleKey);
            saveLog($currentUser, 'remove_role_member', ['removedUser' => $username, 'role' => $roleKey]);
            $_SESSION['flash_ok'] = 'User removed.';
        } else {
            $_SESSION['flash_error'] = 'Unknown action.';
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }

    header('Location: ' . adminUrl('/private/admin/control/index.php'));
    exit;
}

$roles = loadRoles();

$flashOk = $_SESSION['flash_ok'] ?? null;
$flashErr = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Admin Control Panel</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(adminUrl('/private/admin/control/control.css'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" />
</head>
<body>
    <div class="topbar">
        <h1 style="margin:0">Control Panel</h1>
        <a href="<?= htmlspecialchars(adminUrl('/private/admin/index.php'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Admin home</a>
        <a href="<?= htmlspecialchars(adminUrl('/private/admin/logout.php'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Logout</a>
    </div>

    <p class="hint">
        Logged in as <strong><?= h($currentUser) ?></strong>
    </p>

    <?php if (is_string($flashOk) && $flashOk !== ''): ?>
        <div class="ok"><?= h($flashOk) ?></div>
    <?php endif; ?>

    <?php if (is_string($flashErr) && $flashErr !== ''): ?>
        <div class="err"><?= h($flashErr) ?></div>
    <?php endif; ?>

    <?php foreach ($roleDefs as $roleKey => $roleLabel): ?>
        <?php
            $members = $roles[$roleKey] ?? [];
            $memberCount = is_array($members) ? count($members) : 0;
        ?>
        <div class="card">
            <h2 style="margin-top:0">
                <?= h($roleLabel) ?>
                <span class="hint" style="margin-left:8px">(<?= (int)$memberCount ?>)</span>
            </h2>
            <div class="hint">
                Role key: <code><?= h($roleKey) ?></code>
            </div>

            <h3>Members</h3>
            <div class="members">
                <?php if (empty($members)): ?>
                    <span class="hint">No members yet.</span>
                <?php else: ?>
                    <?php foreach ($members as $member): ?>
                        <?php $memberStr = (string)$member; ?>
                        <span class="member">
                            <span><?= h($memberStr) . ' ' . h(getUser($memberStr)['name'] ?? 'Utilizador não fez login ainda') ?></span>

                            <form method="post" action="<?= htmlspecialchars(adminUrl('/private/admin/control/index.php'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" onsubmit="return confirm('Remove this user from the role?');">
                                <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                                <input type="hidden" name="action" value="remove" />
                                <input type="hidden" name="role" value="<?= h($roleKey) ?>" />
                                <input type="hidden" name="username" value="<?= h($memberStr) ?>" />
                                <button type="submit">Remove</button>
                            </form>
                        </span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <h3>Add member</h3>
            <form method="post" action="<?= htmlspecialchars(adminUrl('/private/admin/control/index.php'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                <input type="hidden" name="action" value="add" />
                <input type="hidden" name="role" value="<?= h($roleKey) ?>" />

                <input
                    type="text"
                    name="username"
                    placeholder="istXXXXXXX"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                    pattern="[a-zA-Z0-9_\-]{3,64}"
                    title="Allowed: letters, numbers, _ and - (3–64 chars)"
                    required
                />

                <button type="submit">Add</button>
                <div class="hint">Username guardado deverá ser igual ao istid</div>
            </form>
        </div>
    <?php endforeach; ?>
</body>
</html>

