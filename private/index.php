<?php
declare(strict_types=1);

require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/admin/private/roles.php');

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$u = (is_array($_SESSION['user'] ?? null)) ? $_SESSION['user'] : [];
$logged = !empty($u['istid']);
$istid = (string)($u['istid'] ?? '');
$canAdmin = $logged && $istid !== '' && hasAnyRole($istid);

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>NEB — Portal</title>
    <link rel="stylesheet" href="<?= h(siteUrl('/private/admin/css/admin.css')) ?>" />
    <style>
        .portalHero {
            display: grid;
            gap: 14px;
        }

        .portalActions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 12px;
        }

        .portalNote {
            color: var(--muted);
            font-size: 13px;
            margin: 0;
        }

      .areaTiles {
        margin-top: 12px;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 12px;
      }

      .areaTile {
        border-radius: 16px;
        border: 1px solid rgba(255, 255, 255, 0.16);
        background:
          radial-gradient(circle at 84% 16%, rgba(255, 255, 255, 0.12), rgba(255, 255, 255, 0) 40%),
          rgba(0, 0, 0, 0.16);
        padding: 14px;
        display: grid;
        gap: 10px;
        grid-template-rows: auto 1fr auto;
        min-height: 220px;
        box-shadow: 0 12px 32px rgba(0, 0, 0, 0.22);
      }

      .areaIcon {
        width: 52px;
        height: 52px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 28px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        background: rgba(255, 255, 255, 0.1);
      }

      .areaTitle {
        margin: 0;
        font-size: 18px;
        letter-spacing: 0.2px;
      }

      .areaMeta {
        margin: 6px 0 0;
        color: var(--muted);
        line-height: 1.52;
        font-size: 13px;
      }

      .areaTileActions {
        display: flex;
        justify-content: flex-start;
      }
    </style>
</head>
<body>
  <div class="container">
    <div class="header">
      <div class="brand">
        <div class="logo" aria-hidden="true"></div>
        <div>
          <h1 class="title">Portal NEB</h1>
          <p class="subtitle">Login único para Membro e Admin</p>
        </div>
      </div>

      <div class="nav">
        <a class="button" href="/">Voltar ao site</a>
        <?php if ($logged): ?>
          <a class="button danger" href="<?= h(siteUrl('/private/logout.php')) ?>">Logout</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="grid">
      <?php if (!$logged): ?>
        <div class="card">
          <div class="portalHero">
            <div>
              <h2 style="margin:0 0 6px">Entrar</h2>
              <p class="portalNote">Faz login com o Fénix para aceder ao Portal do Membro.</p>
            </div>

            <div class="portalActions">
              <a class="button primary" href="<?= h(siteUrl('/private/oauth-start.php')) ?>">Login com Fénix</a>
            </div>
          </div>
        </div>
      <?php else: ?>
        <div class="card">
          <div class="kv">
            <div>
              <div class="subtitle">Sessão</div>
              <div style="margin-top:4px"><strong><?= h((string)($u['name'] ?? '')) ?></strong> (<code><?= h($istid) ?></code>)</div>
            </div>
            <div class="badges">
              <span class="badge good">Login ativo</span>
              <?php if ($canAdmin): ?>
                <span class="badge admin">Admin</span>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="card">
          <h2>Áreas</h2>
          <p class="portalNote">Escolhe uma área para entrar.</p>

          <div class="areaTiles">
            <article class="areaTile">
              <div class="areaIcon" aria-hidden="true">🧑‍🎓</div>
              <div>
                <h3 class="areaTitle">Portal do Membro</h3>
                <p class="areaMeta">Editar a tua informação, equipa e perfil pessoal.</p>
              </div>
              <div class="areaTileActions">
                <a class="button primary" href="<?= h(siteUrl('/private/team/index.php')) ?>">Entrar</a>
              </div>
            </article>

            <article class="areaTile">
              <div class="areaIcon" aria-hidden="true">✈️</div>
              <div>
                <h3 class="areaTitle">ErasmusMe</h3>
                <p class="areaMeta">Partilhar e acompanhar a tua história Erasmus.</p>
              </div>
              <div class="areaTileActions">
                <a class="button primary" href="<?= h(siteUrl('/private/erasmus/index.php')) ?>">Entrar</a>
              </div>
            </article>

            <?php if ($canAdmin): ?>
              <article class="areaTile">
                <div class="areaIcon" aria-hidden="true">🛠️</div>
                <div>
                  <h3 class="areaTitle">Admin</h3>
                  <p class="areaMeta">Gestão do site e moderação de conteúdos.</p>
                </div>
                <div class="areaTileActions">
                  <a class="button" href="<?= h(siteUrl('/private/admin/index.php')) ?>">Entrar</a>
                </div>
              </article>
            <?php endif; ?>
          </div>

        </div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
