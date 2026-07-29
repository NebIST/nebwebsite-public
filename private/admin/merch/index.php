<?php
declare(strict_types=1);

require_once(__DIR__ . '/../auth.php');
require_once(__DIR__ . '/../private/roles.php');
require_once(__DIR__ . '/../private/logs.php');

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ensureDir(string $dir): void
{
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Falha ao criar diretoria: ' . $dir);
        }
    }
}

function loadStore(string $path): array
{
    if (!is_file($path)) {
        return ['items' => [], 'timeslots' => []];
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        return ['items' => [], 'timeslots' => []];
    }

    $items = $decoded['items'] ?? [];
    $timeslots = $decoded['timeslots'] ?? [];

    return [
        'items' => is_array($items) ? $items : [],
        'timeslots' => is_array($timeslots) ? $timeslots : [],
    ];
}

function saveStore(string $path, array $store): void
{
    $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('Falha ao gerar JSON.');
    }
    file_put_contents($path, $json);
}

function normalizeText(string $value): string
{
    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
}

function detectMime(string $path): string
{
    if (class_exists('finfo')) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        return (string)$fi->file($path);
    }

    return '';
}

function isAllowedImageMime(string $mime): bool
{
    return in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
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

function lisbonTimeZone(): DateTimeZone
{
    return new DateTimeZone('Europe/Lisbon');
}

function formatLisbonTimestamp(int $timestamp, string $format): string
{
    if ($timestamp <= 0) {
        return '';
    }

    $dateTime = (new DateTimeImmutable('@' . $timestamp))->setTimezone(lisbonTimeZone());
    return $dateTime->format($format);
}

function parseLisbonDateTimeLocal(string $value): int
{
    $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, lisbonTimeZone());
    $errors = DateTimeImmutable::getLastErrors();
    if (!$dateTime || !empty($errors['warning_count']) || !empty($errors['error_count'])) {
        throw new InvalidArgumentException('Data/hora inválida.');
    }

    return $dateTime->getTimestamp();
}

function formatEuroFromCents(int $cents): string
{
    return number_format($cents / 100, 2, ',', ' ') . ' €';
}

function formatTimeslotLabel(array $slot): string
{
    $label = trim((string)($slot['label'] ?? ''));
    $startAt = (int)($slot['start_at'] ?? 0);
    $endAt = (int)($slot['end_at'] ?? 0);
    $location = trim((string)($slot['location'] ?? ''));

    $parts = [];
    if ($label !== '') {
        $parts[] = $label;
    }
    if ($startAt > 0) {
        $range = formatLisbonTimestamp($startAt, 'd/m/Y H:i');
        if ($endAt > $startAt) {
            $range .= ' - ' . formatLisbonTimestamp($endAt, 'H:i');
        }
        $parts[] = $range;
    }
    if ($location !== '') {
        $parts[] = $location;
    }

    return implode(' · ', $parts);
}

$currentUser = (string)($_SESSION['user']['istid'] ?? '');
$canManage = ($currentUser !== '') && (isAdmin($currentUser) || isMerchManager($currentUser));

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

$storageRoot = __DIR__ . '/../../../data/merch';
$photosDir = $storageRoot . '/items';
$jsonPath = $storageRoot . '/merch.info.json';
$maxBytes = 2 * 1024 * 1024;

try {
    ensureDir($storageRoot);
    ensureDir($photosDir);
    if (!is_file($jsonPath)) {
        saveStore($jsonPath, ['items' => [], 'timeslots' => []]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Storage error: ' . $e->getMessage();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    if (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) {
        $_SESSION['flash_error'] = 'Token CSRF inválido. Tenta novamente.';
        header('Location: ' . adminUrl('/private/admin/merch/index.php'));
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    try {
        $store = loadStore($jsonPath);
        $items = $store['items'];
        $timeslots = $store['timeslots'];

        if ($action === 'item_create') {
            $name = normalizeText((string)($_POST['item_name'] ?? ''));
            $category = normalizeText((string)($_POST['item_category'] ?? ''));
            $priceRaw = trim((string)($_POST['item_price'] ?? ''));

            if ($name === '' || $category === '' || $priceRaw === '') {
                throw new InvalidArgumentException('Nome, categoria e preço são obrigatórios.');
            }
            if (mb_strlen($name) > 140 || mb_strlen($category) > 80) {
                throw new InvalidArgumentException('Nome ou categoria demasiado longos.');
            }
            if (!preg_match('/^\d+([.,]\d{1,2})?$/', $priceRaw)) {
                throw new InvalidArgumentException('Preço inválido.');
            }

            $priceValue = (float)str_replace(',', '.', $priceRaw);
            if ($priceValue < 0) {
                throw new InvalidArgumentException('Preço inválido.');
            }
            $priceCents = (int)round($priceValue * 100);

            if (!isset($_FILES['item_image']) || !is_array($_FILES['item_image'])) {
                throw new RuntimeException('Imagem em falta.');
            }

            $file = $_FILES['item_image'];
            $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Falha no upload da imagem (código ' . $err . ').');
            }

            $size = (int)($file['size'] ?? 0);
            if ($size <= 0 || $size > $maxBytes) {
                throw new RuntimeException('A imagem deve ter no máximo 2MB.');
            }

            $tmp = (string)($file['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                throw new RuntimeException('Ficheiro enviado inválido.');
            }

            $mime = detectMime($tmp);
            if ($mime !== '' && !isAllowedImageMime($mime)) {
                throw new RuntimeException('Formato de imagem inválido. Usa jpg, png, gif ou webp.');
            }

            $ext = $mime !== '' ? extensionForMime($mime) : 'jpg';
            $imageName = 'merch_' . bin2hex(random_bytes(10)) . '.' . $ext;
            $stagePath = $photosDir . '/.' . bin2hex(random_bytes(8)) . '.upload';
            $destPath = $photosDir . '/' . $imageName;

            if (!move_uploaded_file($tmp, $stagePath)) {
                throw new RuntimeException('Falha ao guardar imagem.');
            }
            if (!rename($stagePath, $destPath)) {
                @unlink($stagePath);
                throw new RuntimeException('Falha ao finalizar imagem.');
            }

            $id = 'item_' . bin2hex(random_bytes(8));
            $now = time();
            $items[] = [
                'id' => $id,
                'name' => $name,
                'category' => $category,
                'price_cents' => $priceCents,
                'image' => $imageName,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            saveStore($jsonPath, ['items' => $items, 'timeslots' => $timeslots]);

            saveLog($currentUser, 'merch_item_create', [
                'item_id' => $id,
                'name' => $name,
                'category' => $category,
                'price_cents' => $priceCents,
                'image' => $imageName,
            ]);

            $_SESSION['flash_ok'] = 'Artigo criado com sucesso.';
        } elseif ($action === 'item_delete') {
            $itemId = trim((string)($_POST['item_id'] ?? ''));
            if ($itemId === '') {
                throw new InvalidArgumentException('Artigo inválido.');
            }

            $target = null;
            $remaining = [];
            foreach ($items as $item) {
                if (!is_array($item)) continue;
                if ((string)($item['id'] ?? '') === $itemId) {
                    $target = $item;
                    continue;
                }
                $remaining[] = $item;
            }
            if (!is_array($target)) {
                throw new RuntimeException('Artigo não encontrado.');
            }

            $image = trim((string)($target['image'] ?? ''));
            if ($image !== '') {
                $imagePath = $photosDir . '/' . $image;
                if (is_file($imagePath)) {
                    @unlink($imagePath);
                }
            }

            saveStore($jsonPath, ['items' => $remaining, 'timeslots' => $timeslots]);

            saveLog($currentUser, 'merch_item_delete', [
                'item_id' => (string)($target['id'] ?? ''),
                'name' => (string)($target['name'] ?? ''),
                'category' => (string)($target['category'] ?? ''),
            ]);

            $_SESSION['flash_ok'] = 'Artigo removido.';
        } elseif ($action === 'timeslot_create') {
            $label = normalizeText((string)($_POST['slot_label'] ?? ''));
            $startRaw = trim((string)($_POST['slot_start'] ?? ''));
            $endRaw = trim((string)($_POST['slot_end'] ?? ''));
            $location = normalizeText((string)($_POST['slot_location'] ?? ''));

            if ($label === '' || $startRaw === '' || $endRaw === '') {
                throw new InvalidArgumentException('Título, início e fim do horário são obrigatórios.');
            }
            if (mb_strlen($label) > 120 || mb_strlen($location) > 120) {
                throw new InvalidArgumentException('Título ou local demasiado longos.');
            }

            $startAt = parseLisbonDateTimeLocal($startRaw);
            $endAt = parseLisbonDateTimeLocal($endRaw);
            if ($endAt <= $startAt) {
                throw new InvalidArgumentException('O fim tem de ser depois do início.');
            }

            $id = 'slot_' . bin2hex(random_bytes(8));
            $now = time();
            $timeslots[] = [
                'id' => $id,
                'label' => $label,
                'start_at' => (int)$startAt,
                'end_at' => (int)$endAt,
                'location' => $location,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            saveStore($jsonPath, ['items' => $items, 'timeslots' => $timeslots]);

            saveLog($currentUser, 'merch_timeslot_create', [
                'timeslot_id' => $id,
                'label' => $label,
                'start_at' => (int)$startAt,
                'end_at' => (int)$endAt,
                'location' => $location,
            ]);

            $_SESSION['flash_ok'] = 'Horário criado.';
        } elseif ($action === 'timeslot_delete') {
            $slotId = trim((string)($_POST['timeslot_id'] ?? ''));
            if ($slotId === '') {
                throw new InvalidArgumentException('Horário inválido.');
            }

            $target = null;
            $remaining = [];
            foreach ($timeslots as $slot) {
                if (!is_array($slot)) continue;
                if ((string)($slot['id'] ?? '') === $slotId) {
                    $target = $slot;
                    continue;
                }
                $remaining[] = $slot;
            }
            if (!is_array($target)) {
                throw new RuntimeException('Horário não encontrado.');
            }

            saveStore($jsonPath, ['items' => $items, 'timeslots' => $remaining]);

            saveLog($currentUser, 'merch_timeslot_delete', [
                'timeslot_id' => (string)($target['id'] ?? ''),
                'label' => (string)($target['label'] ?? ''),
            ]);

            $_SESSION['flash_ok'] = 'Horário removido.';
        } else {
            throw new RuntimeException('Ação desconhecida.');
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }

    header('Location: ' . adminUrl('/private/admin/merch/index.php'));
    exit;
}

$flashOk = $_SESSION['flash_ok'] ?? null;
$flashErr = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

$store = loadStore($jsonPath);
$items = $store['items'];
$timeslots = $store['timeslots'];

usort($items, static function ($a, $b): int {
    $catCmp = strcasecmp((string)($a['category'] ?? ''), (string)($b['category'] ?? ''));
    if ($catCmp !== 0) {
        return $catCmp;
    }
    return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
});

usort($timeslots, static function ($a, $b): int {
    return (int)($a['start_at'] ?? 0) <=> (int)($b['start_at'] ?? 0);
});

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Merch — Admin</title>
    <link rel="stylesheet" href="<?= h(adminUrl('/private/admin/css/admin.css')) ?>" />
    <link rel="stylesheet" href="<?= h(adminUrl('/private/admin/merch/merch.css')) ?>" />
</head>
<body>
<div class="container">
    <div class="header">
        <div class="brand">
            <img class="loginLogo" style="width: 150px; height: auto;" src="<?= h(adminUrl('/private/admin/images/logocorhorizontal-2.png')) ?>" alt="NEB" />
            <div>
                <h1 class="title">Merch</h1>
                <p class="subtitle">Gestão de artigos e horários de recolha</p>
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
            <h2>Adicionar artigo</h2>
            <p class="subtitle">Campos obrigatórios: nome, preço, categoria e imagem.</p>

            <form method="post" action="<?= h(adminUrl('/private/admin/merch/index.php')) ?>" enctype="multipart/form-data" class="uploadForm">
                <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                <input type="hidden" name="action" value="item_create" />

                <label class="field">
                    <span class="label">Nome do artigo</span>
                    <input type="text" name="item_name" maxlength="140" required />
                </label>

                <div class="row">
                    <label class="field">
                        <span class="label">Categoria</span>
                        <input type="text" name="item_category" maxlength="80" placeholder="T-shirts, Hoodies, Acessórios..." required />
                    </label>

                    <label class="field">
                        <span class="label">Preço (€)</span>
                        <input type="number" name="item_price" min="0" step="0.01" required />
                    </label>
                </div>

                <label class="field">
                    <span class="label">Imagem</span>
                    <input type="file" name="item_image" accept="image/*" required />
                    <span class="help">Máximo 2MB. Formatos: jpg, png, gif, webp.</span>
                </label>

                <div class="row actions">
                    <button class="button primary" type="submit">Adicionar artigo</button>
                </div>
            </form>
        </div>

        <div class="card half">
            <h2>Criar horário</h2>
            <p class="subtitle">Os horários ficam disponíveis no formulário de encomenda.</p>

            <form method="post" action="<?= h(adminUrl('/private/admin/merch/index.php')) ?>" class="uploadForm">
                <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                <input type="hidden" name="action" value="timeslot_create" />

                <label class="field">
                    <span class="label">Título do horário</span>
                    <input type="text" name="slot_label" maxlength="120" placeholder="Recolha na sala do NEB" required />
                </label>

                <div class="row">
                    <label class="field">
                        <span class="label">Início</span>
                        <input type="datetime-local" name="slot_start" required />
                    </label>

                    <label class="field">
                        <span class="label">Fim</span>
                        <input type="datetime-local" name="slot_end" required />
                    </label>
                </div>

                <label class="field">
                    <span class="label">Local (opcional)</span>
                    <input type="text" name="slot_location" maxlength="120" placeholder="Pavilhão, sala ou ponto de encontro" />
                </label>

                <div class="row actions">
                    <button class="button primary" type="submit">Criar horário</button>
                </div>
            </form>
        </div>

        <div class="card" style="grid-column: span 12;">
            <h2>Artigos publicados</h2>

            <?php if (empty($items)): ?>
                <div class="subtitle">Ainda não existem artigos.</div>
            <?php else: ?>
                <div class="tableWrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Imagem</th>
                                <th>Nome</th>
                                <th>Categoria</th>
                                <th>Preço</th>
                                <th>Remover</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items as $item): ?>
                            <?php if (!is_array($item)) continue; ?>
                            <?php
                                $itemId = (string)($item['id'] ?? '');
                                $image = trim((string)($item['image'] ?? ''));
                            ?>
                            <tr>
                                <td>
                                    <?php if ($image !== ''): ?>
                                        <img class="thumb" src="<?= h(siteUrl('/data/merch/items/' . rawurlencode($image))) ?>" alt="Artigo merch" />
                                    <?php endif; ?>
                                </td>
                                <td><strong><?= h((string)($item['name'] ?? '')) ?></strong></td>
                                <td><span class="pill"><?= h((string)($item['category'] ?? '')) ?></span></td>
                                <td><?= h(formatEuroFromCents((int)($item['price_cents'] ?? 0))) ?></td>
                                <td>
                                    <form method="post" action="<?= h(adminUrl('/private/admin/merch/index.php')) ?>" onsubmit="return confirm('Remover este artigo?');">
                                        <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                                        <input type="hidden" name="action" value="item_delete" />
                                        <input type="hidden" name="item_id" value="<?= h($itemId) ?>" />
                                        <button class="button danger" type="submit">Eliminar</button>
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
            <h2>Horários disponíveis</h2>

            <?php if (empty($timeslots)): ?>
                <div class="subtitle">Ainda não existem horários de recolha.</div>
            <?php else: ?>
                <div class="tableWrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Horário</th>
                                <th>Remover</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($timeslots as $slot): ?>
                            <?php if (!is_array($slot)) continue; ?>
                            <?php $slotId = (string)($slot['id'] ?? ''); ?>
                            <tr>
                                <td><?= h(formatTimeslotLabel($slot)) ?></td>
                                <td>
                                    <form method="post" action="<?= h(adminUrl('/private/admin/merch/index.php')) ?>" onsubmit="return confirm('Remover este horário?');">
                                        <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                                        <input type="hidden" name="action" value="timeslot_delete" />
                                        <input type="hidden" name="timeslot_id" value="<?= h($slotId) ?>" />
                                        <button class="button danger" type="submit">Eliminar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>