<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $candidates = [];
    $c1 = realpath(__DIR__ . '/../../');
    if (is_string($c1) && $c1 !== '') $candidates[] = $c1;

    $c2 = realpath(__DIR__ . '/../../../../');
    if (is_string($c2) && $c2 !== '') $candidates[] = $c2;

    $dataRoot = '';
    foreach ($candidates as $cand) {
        if (is_file($cand . '/data/merch/merch.info.json')) {
            $dataRoot = $cand . '/data';
            break;
        }
        if (is_file($cand . '/merch/merch.info.json')) {
            $dataRoot = $cand;
            break;
        }
    }

    if ($dataRoot === '') {
        echo json_encode(['ok' => true, 'items' => [], 'timeslots' => [], 'generatedAt' => time()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $jsonPath = $dataRoot . '/merch/merch.info.json';
    if (!is_file($jsonPath)) {
        echo json_encode(['ok' => true, 'items' => [], 'timeslots' => [], 'generatedAt' => time()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $store = json_decode((string)file_get_contents($jsonPath), true);
    if (!is_array($store)) {
        throw new RuntimeException('Invalid merch store JSON.');
    }

    $itemsRaw = $store['items'] ?? [];
    $timeslotsRaw = $store['timeslots'] ?? [];
    if (!is_array($itemsRaw)) $itemsRaw = [];
    if (!is_array($timeslotsRaw)) $timeslotsRaw = [];

    $items = [];
    foreach ($itemsRaw as $item) {
        if (!is_array($item)) continue;
        $id = trim((string)($item['id'] ?? ''));
        $name = trim((string)($item['name'] ?? ''));
        $category = trim((string)($item['category'] ?? ''));
        $image = trim((string)($item['image'] ?? ''));
        if ($id === '' || $name === '' || $category === '' || $image === '') continue;

        $items[] = [
            'id' => $id,
            'name' => $name,
            'category' => $category,
            'price_cents' => (int)($item['price_cents'] ?? 0),
            'imageUrl' => '/data/merch/items/' . rawurlencode($image),
            'created_at' => (int)($item['created_at'] ?? 0),
            'updated_at' => (int)($item['updated_at'] ?? 0),
        ];
    }

    usort($items, static function ($a, $b): int {
        $catCmp = strcasecmp((string)($a['category'] ?? ''), (string)($b['category'] ?? ''));
        if ($catCmp !== 0) return $catCmp;
        return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });

    $timeslots = [];
    foreach ($timeslotsRaw as $slot) {
        if (!is_array($slot)) continue;
        $id = trim((string)($slot['id'] ?? ''));
        $label = trim((string)($slot['label'] ?? ''));
        $startAt = (int)($slot['start_at'] ?? 0);
        $endAt = (int)($slot['end_at'] ?? 0);
        if ($id === '' || $label === '' || $startAt <= 0 || $endAt <= $startAt) continue;

        $timeslots[] = [
            'id' => $id,
            'label' => $label,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'location' => trim((string)($slot['location'] ?? '')),
        ];
    }

    usort($timeslots, static function ($a, $b): int {
        return (int)($a['start_at'] ?? 0) <=> (int)($b['start_at'] ?? 0);
    });

    echo json_encode([
        'ok' => true,
        'items' => $items,
        'timeslots' => $timeslots,
        'generatedAt' => time(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('list-merch.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['ok' => false, 'items' => [], 'timeslots' => [], 'error' => 'internal_error'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} finally {
    restore_error_handler();
}