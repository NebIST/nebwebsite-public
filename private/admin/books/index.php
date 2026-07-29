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
$canManage = ($currentUser !== '') && (isAdmin($currentUser) || isBooksManager($currentUser));

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

// CSRF
if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$storageRoot = __DIR__ . '/../../../data/books';
$photosDir = $storageRoot . '/photos';
$jsonPath = $storageRoot . '/books.info.json';
$maxBytes = 2 * 1024 * 1024;

function ensureDir(string $dir): void
{
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create directory: ' . $dir);
        }
    }
}

function yearLabel(int $y): string
{
    return $y === 0 ? 'Other' : (string)$y . 'º ano';
}

function validateClassYear(int $y): void
{
    if ($y < 0 || $y > 5) {
        throw new InvalidArgumentException('Invalid year of class (expected 0..5).');
    }
}

function loadStore(string $path): array
{
    if (!is_file($path)) {
        return ['categories' => [], 'books' => []];
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) return ['categories' => [], 'books' => []];

    $cats = $decoded['categories'] ?? [];
    $books = $decoded['books'] ?? [];
    if (!is_array($cats)) $cats = [];
    if (!is_array($books)) $books = [];

    return ['categories' => $cats, 'books' => $books];
}

function saveStore(string $path, array $store): void
{
    $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) throw new RuntimeException('Failed to encode JSON.');
    file_put_contents($path, $json);
}

function normalizeCategoryName(string $s): string
{
    $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');
    return $s;
}

function isAllowedImageMime(string $mime): bool
{
    return in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
}

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

function booksPhotoUrl(string $filename): string
{
    return siteUrl('/data/books/photos/' . rawurlencode($filename));
}

function readRecentBooksLogs(int $limit = 80): array
{
    $path = __DIR__ . '/../private/logs.data.json';
    if (!is_file($path)) return [];

    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) return [];

    $items = $decoded;
    if (isset($decoded['items']) && is_array($decoded['items'])) {
        $items = $decoded['items'];
    }

    $out = [];
    foreach ($items as $row) {
        if (!is_array($row)) continue;
        $action = (string)($row['action'] ?? '');
        if (!str_starts_with($action, 'books_')) continue;
        $out[] = $row;
    }

    usort($out, function ($a, $b) {
        $ta = (int)($a['timestamp'] ?? 0);
        $tb = (int)($b['timestamp'] ?? 0);
        return $tb <=> $ta;
    });

    return array_slice($out, 0, $limit);
}

try {
    ensureDir($storageRoot);
    ensureDir($photosDir);
    if (!is_file($jsonPath)) {
        saveStore($jsonPath, ['categories' => [], 'books' => []]);
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
        $_SESSION['flash_error'] = 'Invalid CSRF token. Please retry.';
        header('Location: ' . adminUrl('/private/admin/books/index.php'));
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    try {
        $store = loadStore($jsonPath);
        $categories = $store['categories'];
        $books = $store['books'];

        if ($action === 'category_create') {
            $year = (int)($_POST['year'] ?? -1);
            validateClassYear($year);

            $name = normalizeCategoryName((string)($_POST['category_name'] ?? ''));
            if ($name === '') throw new InvalidArgumentException('Category name is required.');
            if (mb_strlen($name) > 64) throw new InvalidArgumentException('Category name too long (max 64 chars).');

            // prevent duplicates per year (case-insensitive)
            foreach ($categories as $c) {
                if (!is_array($c)) continue;
                if ((int)($c['year'] ?? -999) !== $year) continue;
                if (mb_strtolower((string)($c['name'] ?? '')) === mb_strtolower($name)) {
                    throw new InvalidArgumentException('That category already exists for this year.');
                }
            }

            $id = 'cat_' . bin2hex(random_bytes(8));
            $categories[] = [
                'id' => $id,
                'year' => $year,
                'name' => $name,
                'created_at' => time(),
            ];

            saveStore($jsonPath, ['categories' => $categories, 'books' => $books]);

            saveLog($currentUser, 'books_category_create', [
                'year' => $year,
                'category_id' => $id,
                'category_name' => $name,
            ]);

            $_SESSION['flash_ok'] = 'Category created.';
        } elseif ($action === 'category_delete') {
            $catId = (string)($_POST['category_id'] ?? '');
            if ($catId === '') throw new InvalidArgumentException('Missing category id.');

            $cat = null;
            foreach ($categories as $c) {
                if (is_array($c) && (string)($c['id'] ?? '') === $catId) { $cat = $c; break; }
            }
            if (!$cat) throw new RuntimeException('Category not found.');

            // delete all books in this category (and their photos)
            $deletedBooks = 0;
            $deletedPhotos = 0;
            $remainingBooks = [];
            foreach ($books as $b) {
                if (!is_array($b)) continue;
                if ((string)($b['categoryId'] ?? '') === $catId) {
                    $deletedBooks++;
                    $photo = (string)($b['photo'] ?? '');
                    if ($photo !== '') {
                        $p = $photosDir . '/' . $photo;
                        if (is_file($p) && @unlink($p)) $deletedPhotos++;
                    }
                    continue;
                }
                $remainingBooks[] = $b;
            }

            $remainingCats = [];
            foreach ($categories as $c) {
                if (!is_array($c)) continue;
                if ((string)($c['id'] ?? '') === $catId) continue;
                $remainingCats[] = $c;
            }

            saveStore($jsonPath, ['categories' => $remainingCats, 'books' => $remainingBooks]);

            saveLog($currentUser, 'books_category_delete', [
                'category_id' => (string)($cat['id'] ?? ''),
                'category_name' => (string)($cat['name'] ?? ''),
                'year' => (int)($cat['year'] ?? -1),
                'deleted_books' => $deletedBooks,
                'deleted_photos' => $deletedPhotos,
            ]);

            $_SESSION['flash_ok'] = 'Category deleted (and all books inside).';
        } elseif ($action === 'book_create') {
            $year = (int)($_POST['year'] ?? -1);
            validateClassYear($year);

            $categoryId = (string)($_POST['category_id'] ?? '');
            if ($categoryId === '') throw new InvalidArgumentException('Category is required.');

            // category must exist and match year
            $catOk = false;
            foreach ($categories as $c) {
                if (!is_array($c)) continue;
                if ((string)($c['id'] ?? '') === $categoryId && (int)($c['year'] ?? -999) === $year) {
                    $catOk = true;
                    break;
                }
            }
            if (!$catOk) throw new InvalidArgumentException('Invalid category for selected year.');

            $name = trim((string)($_POST['book_name'] ?? ''));
            if ($name === '') throw new InvalidArgumentException('Book name is required.');
            if (mb_strlen($name) > 140) throw new InvalidArgumentException('Book name too long (max 140 chars).');

            $author = trim((string)($_POST['book_author'] ?? ''));
            if (mb_strlen($author) > 140) throw new InvalidArgumentException('Author too long (max 140 chars).');

            $edition = trim((string)($_POST['book_edition'] ?? ''));
            if (mb_strlen($edition) > 80) throw new InvalidArgumentException('Edition too long (max 80 chars).');

            $scholarYear = trim((string)($_POST['book_scholar_year'] ?? ''));
            if (mb_strlen($scholarYear) > 32) throw new InvalidArgumentException('Scholar year too long (max 32 chars).');

            $state = trim((string)($_POST['book_state'] ?? ''));
            if ($state === '') throw new InvalidArgumentException('State/Description is required.');
            if (mb_strlen($state) > 2000) throw new InvalidArgumentException('State/Description too long (max 2000 chars).');

            $priceRaw = trim((string)($_POST['book_price'] ?? ''));
            if ($priceRaw === '') throw new InvalidArgumentException('Price is required.');
            if (!preg_match('/^\d+([.,]\d{1,2})?$/', $priceRaw)) throw new InvalidArgumentException('Invalid price format.');
            $priceRaw = str_replace(',', '.', $priceRaw);
            $priceFloat = (float)$priceRaw;
            if ($priceFloat <= 0) throw new InvalidArgumentException('Price must be greater than 0.');
            $priceCents = (int)round($priceFloat * 100);

            // upload photo
            if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
                throw new RuntimeException('Missing photo upload.');
            }

            $f = $_FILES['photo'];
            $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Upload failed (code ' . $err . ').');
            }

            $size = (int)($f['size'] ?? 0);
            if ($size <= 0) throw new RuntimeException('Empty photo.');
            if ($size > $maxBytes) throw new RuntimeException('Photo too large. Max 2MB.');

            $tmp = (string)($f['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                throw new RuntimeException('Invalid uploaded file.');
            }

            $mime = detectMime($tmp);
            if ($mime !== '' && !isAllowedImageMime($mime)) {
                throw new RuntimeException('Invalid image type. Allowed: jpg, png, gif, webp.');
            }
            $ext = $mime !== '' ? extensionForMime($mime) : 'jpg';
            $photoName = 'b_' . bin2hex(random_bytes(10)) . '.' . $ext;

            $stagePath = $photosDir . '/.' . bin2hex(random_bytes(8)) . '.upload';
            $destPath = $photosDir . '/' . $photoName;

            if (!move_uploaded_file($tmp, $stagePath)) {
                throw new RuntimeException('Failed to store uploaded photo.');
            }
            if (!rename($stagePath, $destPath)) {
                @unlink($stagePath);
                throw new RuntimeException('Failed to finalize stored photo.');
            }

            $id = 'book_' . bin2hex(random_bytes(8));
            $now = time();

            $books[] = [
                'id' => $id,
                'year' => $year,
                'categoryId' => $categoryId,
                'name' => $name,
                'author' => $author,
                'edition' => $edition,
                'scholarYear' => $scholarYear,
                'state' => $state,
                'price_cents' => $priceCents,
                'photo' => $photoName,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            saveStore($jsonPath, ['categories' => $categories, 'books' => $books]);

            saveLog($currentUser, 'books_book_create', [
                'book_id' => $id,
                'year' => $year,
                'category_id' => $categoryId,
                'name' => $name,
                'price_cents' => $priceCents,
                'photo' => $photoName,
            ]);

            $_SESSION['flash_ok'] = 'Livro Criado.';
        } elseif ($action === 'book_delete') {
            $bookId = (string)($_POST['book_id'] ?? '');
            if ($bookId === '') throw new InvalidArgumentException('Missing book id.');

            $target = null;
            $remaining = [];
            foreach ($books as $b) {
                if (!is_array($b)) continue;
                if ((string)($b['id'] ?? '') === $bookId) {
                    $target = $b;
                    continue;
                }
                $remaining[] = $b;
            }
            if (!$target) throw new RuntimeException('Book not found.');

            $photo = (string)($target['photo'] ?? '');
            $deletedPhoto = false;
            if ($photo !== '') {
                $p = $photosDir . '/' . $photo;
                if (is_file($p)) $deletedPhoto = @unlink($p);
            }

            saveStore($jsonPath, ['categories' => $categories, 'books' => $remaining]);

            saveLog($currentUser, 'books_book_delete', [
                'book_id' => (string)($target['id'] ?? ''),
                'name' => (string)($target['name'] ?? ''),
                'year' => (int)($target['year'] ?? -1),
                'category_id' => (string)($target['categoryId'] ?? ''),
                'photo' => $photo,
                'deleted_photo' => $deletedPhoto,
            ]);

            $_SESSION['flash_ok'] = 'Livro eliminado.';
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }

    header('Location: ' . adminUrl('/private/admin/books/index.php'));
    exit;
}

header('Content-Type: text/html; charset=utf-8');

$store = loadStore($jsonPath);
$categories = $store['categories'];
$books = $store['books'];

$flashOk = $_SESSION['flash_ok'] ?? null;
$flashErr = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

$logs = readRecentBooksLogs(80);

// Sort categories by year then name
usort($categories, function ($a, $b) {
    $ya = (int)($a['year'] ?? 0);
    $yb = (int)($b['year'] ?? 0);
    if ($ya !== $yb) return $ya <=> $yb;
    return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
});

// Build category lookup
$catById = [];
foreach ($categories as $c) {
    if (!is_array($c)) continue;
    $id = (string)($c['id'] ?? '');
    if ($id !== '') $catById[$id] = $c;
}

// Books list: optional filter/sort via query params
$booksAll = $books;

$sortModeRaw = (string)($_GET['sort'] ?? 'recent');
$sortMode = in_array($sortModeRaw, ['recent', 'year_category'], true) ? $sortModeRaw : 'recent';

$filterYear = -1; // -1 = all
$fyRaw = $_GET['fy'] ?? 'all';
if (is_string($fyRaw) && $fyRaw !== '' && $fyRaw !== 'all') {
    $filterYear = (int)$fyRaw;
    validateClassYear($filterYear);
}

$filterCategory = (string)($_GET['fcat'] ?? 'all');
if ($filterCategory === 'all') $filterCategory = '';
if ($filterCategory !== '' && !isset($catById[$filterCategory])) {
    $filterCategory = '';
}
if ($filterCategory !== '' && $filterYear !== -1) {
    $cy = (int)($catById[$filterCategory]['year'] ?? -999);
    if ($cy !== $filterYear) $filterCategory = '';
}

$books = array_values(array_filter($books, function ($b) use ($filterYear, $filterCategory) {
    if (!is_array($b)) return false;
    if ($filterYear !== -1 && (int)($b['year'] ?? -999) !== $filterYear) return false;
    if ($filterCategory !== '' && (string)($b['categoryId'] ?? '') !== $filterCategory) return false;
    return true;
}));

if ($sortMode === 'year_category') {
    usort($books, function ($a, $b) use ($catById) {
        $ya0 = (int)($a['year'] ?? 0);
        $yb0 = (int)($b['year'] ?? 0);

        // Put "Other" (0) last
        $ya = ($ya0 === 0) ? 99 : $ya0;
        $yb = ($yb0 === 0) ? 99 : $yb0;
        if ($ya !== $yb) return $ya <=> $yb;

        $caId = (string)($a['categoryId'] ?? '');
        $cbId = (string)($b['categoryId'] ?? '');
        $caName = (string)($catById[$caId]['name'] ?? '');
        $cbName = (string)($catById[$cbId]['name'] ?? '');
        $cc = strcmp(mb_strtolower($caName), mb_strtolower($cbName));
        if ($cc !== 0) return $cc;

        $na = (string)($a['name'] ?? '');
        $nb = (string)($b['name'] ?? '');
        $nc = strcmp(mb_strtolower($na), mb_strtolower($nb));
        if ($nc !== 0) return $nc;

        // tie-breaker: newest first
        $ua = (int)($a['updated_at'] ?? 0);
        $ub = (int)($b['updated_at'] ?? 0);
        return $ub <=> $ua;
    });
} else {
    // Recent first (default)
    usort($books, function ($a, $b) {
        $ua = (int)($a['updated_at'] ?? 0);
        $ub = (int)($b['updated_at'] ?? 0);
        return $ub <=> $ua;
    });
}

function fmtEuroFromCents(int $cents): string
{
    return number_format($cents / 100, 2, '.', '') . ' €';
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Livros e Sebentas — Admin</title>
    <link rel="stylesheet" href="<?= h(adminUrl('/private/admin/css/admin.css')) ?>" />
    <link rel="stylesheet" href="<?= h(adminUrl('/private/admin/books/books.css')) ?>" />
</head>
<body>
<div class="container">
    <div class="header">
        <div class="brand">
            <img class="loginLogo" style="width: 150px; height: auto;" src="<?= htmlspecialchars(adminUrl('/private/admin/images/logocorhorizontal-2.png'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="NEB" />
            <div>
                <h1 class="title">Livros e Sebantas</h1>
                <p class="subtitle">Categorias por ano (0..5) e livros à venda</p>
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
            <h2>Adicionar livro</h2>
            <p class="subtitle" style="margin-top:0">
                Foto obrigatória (max <strong><?= (int)($maxBytes / (1024*1024)) ?>MB</strong>). Formatos: jpg/jpeg/png/gif/webp.
            </p>

            <form method="post" action="<?= h(adminUrl('/private/admin/books/index.php')) ?>" enctype="multipart/form-data" class="uploadForm" id="bookForm">
                <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                <input type="hidden" name="action" value="book_create" />

                <div class="row">
                    <label class="field">
                        <span class="label">Ano da cadeira</span>
                        <select name="year" id="bookYear" required>
                            <?php for ($y = 0; $y <= 5; $y++): ?>
                                <option value="<?= $y ?>"><?= h(yearLabel($y)) ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>

                    <label class="field">
                        <span class="label">Categoria</span>
                        <select name="category_id" id="bookCategory" required>
                            <?php foreach ($categories as $c): ?>
                                <?php
                                    $cid = (string)($c['id'] ?? '');
                                    $cy = (int)($c['year'] ?? 0);
                                    $cn = (string)($c['name'] ?? '');
                                    if ($cid === '' || $cn === '') continue;
                                ?>
                                <option value="<?= h($cid) ?>" data-year="<?= (int)$cy ?>">
                                    <?= h(yearLabel($cy) . ' — ' . $cn) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="help">A lista filtra pelo ano escolhido.</span>
                    </label>
                </div>

                <label class="field">
                    <span class="label">Nome</span>
                    <input type="text" name="book_name" maxlength="140" required />
                </label>

                <div class="row">
                    <label class="field">
                        <span class="label">Autor (opcional)</span>
                        <input type="text" name="book_author" maxlength="140" />
                    </label>

                    <label class="field">
                        <span class="label">Edição (opcional)</span>
                        <input type="text" name="book_edition" maxlength="80" />
                    </label>
                </div>

                <div class="row">
                    <label class="field">
                        <span class="label">Ano letivo do livro (opcional)</span>
                        <input type="text" name="book_scholar_year" maxlength="32" placeholder="ex: 2024/2025" />
                    </label>

                    <label class="field">
                        <span class="label">Preço (€)</span>
                        <input type="number" name="book_price" min="0.01" step="0.01" required />
                    </label>
                </div>

                <label class="field">
                    <span class="label">Estado / Descrição</span>
                    <textarea name="book_state" maxlength="2000" rows="5" required placeholder="ex: Como novo / algumas anotações..."></textarea>
                </label>

                <div class="dropzone" id="dropzone" role="button" tabindex="0" aria-label="Solte a foto aqui ou escolha um arquivo">
                    <div class="dzTitle">Arraste e solte a foto para aqui</div>
                    <div class="dzSub">ou clique para escolher</div>
                    <div class="dzMeta" id="dzMeta">Nenhum arquivo selecionado</div>
                </div>
                <input class="fileInput" type="file" name="photo" id="photoInput" accept="image/*" required />

                <div class="row actions">
                    <button class="button primary" type="submit">Adicionar</button>
                </div>
            </form>

            <?php if (empty($categories)): ?>
                <div class="subtitle" style="margin-top:10px">
                    Nota: crie primeiro uma categoria (à direita) para conseguir adicionar livros.
                </div>
            <?php endif; ?>
        </div>

        <div class="card half">
            <h2>Categorias (por ano)</h2>
            <p class="subtitle" style="margin-top:0">
                Ao apagar uma categoria, todos os livros nessa categoria (e as fotos) são apagados.
            </p>

            <form method="post" action="<?= h(adminUrl('/private/admin/books/index.php')) ?>" class="uploadForm">
                <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                <input type="hidden" name="action" value="category_create" />

                <div class="row">
                    <label class="field">
                        <span class="label">Ano</span>
                        <select name="year" required>
                            <?php for ($y = 0; $y <= 5; $y++): ?>
                                <option value="<?= $y ?>"><?= h(yearLabel($y)) ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>

                    <label class="field">
                        <span class="label">Nome da categoria</span>
                        <input type="text" name="category_name" maxlength="64" required placeholder="ex: Bioquímica, Cálculo, ..." />
                    </label>
                </div>

                <div class="row actions">
                    <button class="button primary" type="submit">Criar categoria</button>
                </div>
            </form>

            <div class="tableWrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Ano</th>
                            <th>Categoria</th>
                            <th>Delete</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($categories)): ?>
                        <tr><td colspan="3"><span class="subtitle">Sem categorias ainda.</span></td></tr>
                    <?php else: ?>
                        <?php foreach ($categories as $c): ?>
                            <?php
                                $cid = (string)($c['id'] ?? '');
                                $cy = (int)($c['year'] ?? 0);
                                $cn = (string)($c['name'] ?? '');
                                if ($cid === '' || $cn === '') continue;
                            ?>
                            <tr>
                                <td><?= h(yearLabel($cy)) ?></td>
                                <td><strong><?= h($cn) ?></strong></td>
                                <td>
                                    <form method="post" action="<?= h(adminUrl('/private/admin/books/index.php')) ?>"
                                          onsubmit="return confirm('Apagar a categoria &quot;<?= h($cn) ?>&quot; (<?= h(yearLabel($cy)) ?>) e TODOS os livros dentro?');">
                                        <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                                        <input type="hidden" name="action" value="category_delete" />
                                        <input type="hidden" name="category_id" value="<?= h($cid) ?>" />
                                        <button class="button danger" type="submit">Eliminar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card" style="grid-column: span 12;">
            <h2>Livros publicados</h2>

            <form method="get" action="<?= h(adminUrl('/private/admin/books/index.php')) ?>" class="toolbar">
                <div class="row">
                    <label class="field small">
                        <span class="label">Ordenar</span>
                        <select name="sort">
                            <option value="recent" <?= $sortMode === 'recent' ? 'selected' : '' ?>>Recentes</option>
                            <option value="year_category" <?= $sortMode === 'year_category' ? 'selected' : '' ?>>Ano → Categoria</option>
                        </select>
                    </label>

                    <label class="field small">
                        <span class="label">Filtrar ano</span>
                        <select name="fy" id="filterYear">
                            <option value="all" <?= $filterYear === -1 ? 'selected' : '' ?>>Todos</option>
                            <?php for ($y = 0; $y <= 5; $y++): ?>
                                <option value="<?= $y ?>" <?= $filterYear === $y ? 'selected' : '' ?>><?= h(yearLabel($y)) ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>

                    <label class="field" style="flex: 2 1 240px;">
                        <span class="label">Filtrar categoria</span>
                        <select name="fcat" id="filterCategory">
                            <option value="all" <?= $filterCategory === '' ? 'selected' : '' ?>>Todas</option>
                            <?php foreach ($categories as $c): ?>
                                <?php
                                    $cid = (string)($c['id'] ?? '');
                                    $cy = (int)($c['year'] ?? 0);
                                    $cn = (string)($c['name'] ?? '');
                                    if ($cid === '' || $cn === '') continue;
                                ?>
                                <option value="<?= h($cid) ?>" data-year="<?= (int)$cy ?>" <?= $filterCategory === $cid ? 'selected' : '' ?>>
                                    <?= h(yearLabel($cy) . ' — ' . $cn) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="help">Escolha um ano para ver apenas as categorias desse ano.</span>
                    </label>
                </div>
                <div class="row actions" style="margin-top: 0;">
                    <button class="button" type="submit">Aplicar</button>
                    <a class="button" href="<?= h(adminUrl('/private/admin/books/index.php')) ?>">Limpar</a>
                </div>
            </form>

            <?php if (empty($booksAll)): ?>
                <div class="subtitle">Ainda não existem livros.</div>
            <?php elseif (empty($books)): ?>
                <div class="subtitle">Nenhum livro encontrado com estes filtros.</div>
            <?php else: ?>
                <div class="tableWrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Foto</th>
                                <th>Info</th>
                                <th>Categoria</th>
                                <th>Preço</th>
                                <th>Delete</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($books as $b): ?>
                            <?php
                                if (!is_array($b)) continue;
                                $bid = (string)($b['id'] ?? '');
                                $name = (string)($b['name'] ?? '');
                                if ($bid === '' || $name === '') continue;

                                $year = (int)($b['year'] ?? 0);
                                $author = (string)($b['author'] ?? '');
                                $edition = (string)($b['edition'] ?? '');
                                $sy = (string)($b['scholarYear'] ?? '');
                                $state = (string)($b['state'] ?? '');
                                $priceC = (int)($b['price_cents'] ?? 0);
                                $photo = (string)($b['photo'] ?? '');
                                $catId = (string)($b['categoryId'] ?? '');
                                $cat = $catById[$catId] ?? null;
                                $catLabel = $cat && is_array($cat) ? (yearLabel((int)($cat['year'] ?? 0)) . ' — ' . (string)($cat['name'] ?? '')) : '—';
                            ?>
                            <tr>
                                <td>
                                    <?php if ($photo !== ''): ?>
                                        <img class="thumb" src="<?= h(booksPhotoUrl($photo)) ?>" alt="<?= h($name) ?>" />
                                    <?php else: ?>
                                        <span class="subtitle">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-weight:650"><?= h($name) ?></div>
                                    <div class="subtitle">
                                        <?= h(yearLabel($year)) ?>
                                        <?php if ($author !== ''): ?> • <?= h($author) ?><?php endif; ?>
                                        <?php if ($edition !== ''): ?> • <?= h($edition) ?><?php endif; ?>
                                        <?php if ($sy !== ''): ?> • <?= h($sy) ?><?php endif; ?>
                                    </div>
                                    <?php if (trim($state) !== ''): ?>
                                        <div class="miniNote"><?= h($state) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="pill"><?= h($catLabel) ?></span></td>
                                <td><strong><?= h(fmtEuroFromCents($priceC)) ?></strong></td>
                                <td>
                                    <form method="post" action="<?= h(adminUrl('/private/admin/books/index.php')) ?>"
                                          onsubmit="return confirm('Eliminar o livro &quot;<?= h($name) ?>&quot;?');">
                                        <input type="hidden" name="csrf" value="<?= h((string)$_SESSION['csrf']) ?>" />
                                        <input type="hidden" name="action" value="book_delete" />
                                        <input type="hidden" name="book_id" value="<?= h($bid) ?>" />
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
            <h2>Logs recentes</h2>
            <?php if (empty($logs)): ?>
                <div class="subtitle">Sem logs de livros.</div>
            <?php else: ?>
                <div class="logList">
                    <?php foreach ($logs as $row): ?>
                        <?php
                            $ts = (int)($row['timestamp'] ?? 0);
                            $when = $ts > 0 ? date('Y-m-d H:i:s', $ts) : 'hora desconhecida';
                            $actor = (string)($row['istId'] ?? '');
                            $action = (string)($row['action'] ?? '');
                            $details = $row['details'] ?? null;
                        ?>
                        <div class="logRow">
                            <div class="logMain">
                                <div><strong><?= h($action) ?></strong></div>
                                <div class="subtitle"><?= h($when) ?> • <?= h($actor) ?></div>
                            </div>
                            <?php if (is_array($details) && !empty($details)): ?>
                                <div class="logDetails">
                                    <pre><?= h(json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '') ?></pre>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(() => {
  // Dropzone meta
  const dz = document.getElementById('dropzone');
  const input = document.getElementById('photoInput');
  const meta = document.getElementById('dzMeta');

    // Prevent the browser from navigating away when a file is dropped outside.
    ['dragover', 'drop'].forEach((evt) => {
        window.addEventListener(evt, (e) => {
            e.preventDefault();
        }, { passive: false });
    });

    const MAX_BYTES = <?= (int)$maxBytes ?>;
    const ALLOWED = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

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
        if (Number.isFinite(file.size) && file.size > MAX_BYTES) {
            meta.textContent = file.name + ' • ' + fmtBytes(file.size) + ' — ficheiro demasiado grande (max 2MB)';
            dz.classList.remove('hasFile');
            return;
        }
        if (file.type && !ALLOWED.includes(file.type)) {
            meta.textContent = file.name + ' • tipo inválido (use jpg/png/gif/webp)';
            dz.classList.remove('hasFile');
            return;
        }
    meta.textContent = file.name + ' • ' + fmtBytes(file.size);
    dz.classList.add('hasFile');
  }

  input.addEventListener('change', () => setMeta(input.files && input.files[0]));

    // Click / keyboard open file picker.
    dz.addEventListener('click', (e) => {
        e.preventDefault();
        input.click();
    });
    dz.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            input.click();
        }
    });

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

    const file = files[0];

        // Try to set the file input programmatically (works in Chromium/Firefox).
        // If the browser blocks it (some Safari versions), we still show the filename.
        try {
            if (typeof DataTransfer !== 'undefined') {
                const dt = new DataTransfer();
                dt.items.add(file);
                input.files = dt.files;
            }
        } catch (_) {
            // Ignore – user can click and select manually.
        }

        setMeta(input.files && input.files[0] ? input.files[0] : file);
  });

  setMeta(input.files && input.files[0]);

  // Filter categories by selected year
  const yearSel = document.getElementById('bookYear');
  const catSel = document.getElementById('bookCategory');

  function filterCats() {
    const year = String(yearSel.value);
    const opts = Array.from(catSel.options);

    let firstVisible = null;
    for (const o of opts) {
      const oy = o.getAttribute('data-year');
      const ok = (oy === year);
      o.hidden = !ok;
      o.disabled = !ok;
      if (ok && !firstVisible) firstVisible = o;
    }

    // if current selected is hidden, select first visible
    const selected = catSel.options[catSel.selectedIndex];
    if (!selected || selected.disabled) {
      if (firstVisible) firstVisible.selected = true;
    }
  }

  if (yearSel && catSel) {
    yearSel.addEventListener('change', filterCats);
    filterCats();
  }

    // Filter toolbar categories (Livros publicados)
    const fYearSel = document.getElementById('filterYear');
    const fCatSel = document.getElementById('filterCategory');

    function filterToolbarCats() {
        if (!fYearSel || !fCatSel) return;
        const year = String(fYearSel.value);
        const allYears = (year === 'all' || year === '' || year === '-1');
        const opts = Array.from(fCatSel.options);

        for (const o of opts) {
            const oy = o.getAttribute('data-year');
            if (!oy) {
                // "Todas" option
                o.hidden = false;
                o.disabled = false;
                continue;
            }

            const ok = allYears || (oy === year);
            o.hidden = !ok;
            o.disabled = !ok;
        }

        const selected = fCatSel.options[fCatSel.selectedIndex];
        if (selected && selected.disabled) {
            fCatSel.value = 'all';
        }
    }

    if (fYearSel && fCatSel) {
        fYearSel.addEventListener('change', filterToolbarCats);
        filterToolbarCats();
    }
})();
</script>
</body>
</html>