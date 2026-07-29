<?php
header('Content-Type: application/json');

// filesystem dir for PDFs
$pdfDir = realpath(__DIR__ . '/../nebletter') ?: '';

$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/data/scripts/list-pdfs.php';
$scriptDir = dirname($scriptName);

if (substr($scriptDir, -8) === '/scripts') {
    $pdfUrlBase = substr($scriptDir, 0, -8) . '/nebletter/';
} else {
    $pdfUrlBase = $scriptDir . '/nebletter/';
}
$pdfUrlBase = '/' . ltrim(preg_replace('#/+#', '/', $pdfUrlBase), '/'); 

$count = isset($_GET['count']) ? intval($_GET['count']) : -1;

$months = [
    'january' => 1,
    'february' => 2,
    'march' => 3,
    'april' => 4,
    'may' => 5,
    'june' => 6,
    'july' => 7,
    'august' => 8,
    'september' => 9,
    'october' => 10,
    'november' => 11,
    'december' => 12
];

$pdfs = [];
if (is_dir($pdfDir)) {
    foreach (scandir($pdfDir) as $file) {
        // ignore directories and hidden files
        if ($file === '.' || $file === '..') continue;
        $full = $pdfDir . DIRECTORY_SEPARATOR . $file;
        if (!is_file($full)) continue;

        if (preg_match('/^(\d{4})_([a-zA-Z]+)\.pdf$/', $file, $matches)) {
            $year = (int)$matches[1];
            $month = strtolower($matches[2]);

            if (isset($months[$month])) {
                $pdfs[] = [
                    'name' => $file,
                    'url' => $pdfUrlBase . $file,
                    'year' => $year,
                    'month' => $month,
                    'month_num' => $months[$month]
                ];
            }
        }
    }
}

// Sort by year (desc), then month number (desc)
usort($pdfs, function ($a, $b) {
    return ($b['year'] <=> $a['year']) ?: ($b['month_num'] <=> $a['month_num']);
});

// Limit results if count is set and not -1
if ($count !== -1) {
    $pdfs = array_slice($pdfs, 0, $count);
}

echo json_encode($pdfs, JSON_UNESCAPED_SLASHES);