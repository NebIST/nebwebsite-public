<?php
require_once(__DIR__ . '/auth.php');
require_once(__DIR__ . '/private/roles.php');

$istid = (string) ($_SESSION['user']['istid'] ?? '');
$isAdminUser = isAdmin($istid);
$roles = retreiveUserRoles($istid);
$roleLabels = getDefinedRoles();

$flashOk = $_SESSION['flash_ok'] ?? null;
$flashErr = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);
?>

<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Admin</title>
  <link rel="stylesheet" href="<?= htmlspecialchars(adminUrl('/private/admin/css/admin.css'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" />
</head>
<body>
  <div class="container">
    <div class="header">
      <div class="brand">
        <img class="loginLogo" style="width: 150px; height: auto;" src="<?= htmlspecialchars(adminUrl('/private/admin/images/logocorhorizontal-2.png'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="NEB" />
        <div>
          <h1 class="title">Admin</h1>
          <p class="subtitle">Área de gestão do site Neb</p>
        </div>
      </div>
      <div class="nav">
        <a class="button danger" href="<?= htmlspecialchars(adminUrl('/private/admin/logout.php'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Logout</a>
        <a class="button" href="<?= htmlspecialchars(siteUrl('/'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Voltar ao site</a>
      </div>
    </div>

    <div class="grid">
      <?php if (is_string($flashOk) && $flashOk !== ''): ?>
        <div class="card" style="grid-column: span 12; padding: 0; background: transparent; border: 0; box-shadow: none;">
          <div class="alert ok"><?= htmlspecialchars($flashOk, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        </div>
      <?php endif; ?>
      <?php if (is_string($flashErr) && $flashErr !== ''): ?>
        <div class="card" style="grid-column: span 12; padding: 0; background: transparent; border: 0; box-shadow: none;">
          <div class="alert err"><?= htmlspecialchars($flashErr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        </div>
      <?php endif; ?>

      <div class="card half">
        <h2>Session</h2>
        <div class="kv">
          <div>
            <div style="font-size:14px;color:rgba(234,240,255,0.75)">Logged in as</div>
            <div style="font-size:18px;font-weight:650;letter-spacing:0.2px">
              <?= htmlspecialchars($istid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>
          </div>
          <div class="badges">
            <?php if ($isAdminUser): ?>
              <span class="badge admin">Admin</span>
            <?php endif; ?>
            <?php if (!empty($roles)): ?>
              <span class="badge good"><?= count($roles) ?> função(ões)</span>
            <?php else: ?>
              <span class="badge">Sem funções</span>
            <?php endif; ?>
          </div>
        </div>

        <?php if (!empty($roles)): ?>
          <div style="margin-top:12px" class="badges">
            <?php foreach ($roles as $roleKey): ?>
              <?php $label = $roleLabels[$roleKey] ?? $roleKey; ?>
              <span class="badge"><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="card half">
        <h2>Tools</h2>
        <div class="links">
          <?php if ($isAdminUser): ?>
            <div class="linkRow">
              <div>
                <div style="font-weight:600">Gestão de Membros</div>
                <div class="meta">Adicionar/remover utilizadores de funções</div>
              </div>
              <a class="button primary" href="<?= htmlspecialchars(adminUrl('/private/admin/control/index.php'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Abrir</a>
            </div>
          <?php endif; ?>

          <?php if (isSiteManager($istid) || $isAdminUser): ?>
            <div class="linkRow">
              <div>
                <div style="font-weight:600">Teams</div>
                <div class="meta">Gestão de departamentos e membros</div>
              </div>
              <a class="button primary" href="<?= htmlspecialchars(adminUrl('/private/admin/teams/index.php'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Abrir</a>
            </div>
          <?php endif; ?>

          <?php if (isLetterEditor($istid) || $isAdminUser): ?>
            <div class="linkRow">
              <div>
                <div style="font-weight:600">NebLetter</div>
                <div class="meta">Gestão de conteúdo do NebLetter</div>
              </div>
              <a class="button primary" href="<?= htmlspecialchars(adminUrl('/private/admin/nebletter/index.php'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Abrir</a>
            </div>
          <?php endif; ?>

          <?php if (isActivityManager($istid) || $isAdminUser): ?>
            <div class="linkRow">
              <div>
                <div style="font-weight:600">Atividades</div>
                <div class="meta">Gestão de Atividades</div>
              </div>
              <a class="button primary" href="<?= htmlspecialchars(adminUrl('/private/admin/activities/index.php'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Abrir</a>
            </div>
          <?php endif; ?>

          <?php if (isBooksManager($istid) || $isAdminUser): ?>
            <div class="linkRow">
              <div>
                <div style="font-weight:600">Livros e Sebentas</div>
                <div class="meta">Gestão de Livros e Sebentas</div>
              </div>
              <a class="button primary" href="<?= htmlspecialchars(adminUrl('/private/admin/books/index.php'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Abrir</a>
            </div>
          <?php endif; ?>

          <?php if (isErasmusManager($istid) || $isAdminUser): ?>
            <div class="linkRow">
              <div>
                <div style="font-weight:600">Erasmus</div>
                <div class="meta">Aprovação e gestão de Erasmus</div>
              </div>
              <a class="button primary" href="<?= htmlspecialchars(adminUrl('/private/admin/erasmus/index.php'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Abrir</a>
            </div>
          <?php endif; ?>

          <?php if (isMerchManager($istid) || $isAdminUser): ?>
            <div class="linkRow">
              <div>
                <div style="font-weight:600">Merch</div>
                <div class="meta">Gestão de artigos e horários de recolha</div>
              </div>
              <a class="button primary" href="<?= htmlspecialchars(adminUrl('/private/admin/merch/index.php'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Abrir</a>
            </div>
          <?php endif; ?>
        </div>
        <?php if (!hasAnyRole($istid)): ?>
          <div class="footer">Nenhuma ferramenta está disponível para as suas funções por enquanto. Acesso indevido.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>