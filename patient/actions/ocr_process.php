<?php
session_start();
require_once __DIR__ . '/../../database/queries.php';
require_once __DIR__ . '/../../database/ocr_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || strtoupper($_SESSION['role'] ?? '') !== 'PATIENT') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'You must be logged in as a patient.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Invalid request method.']);
    exit;
}
if (empty($_FILES['prescriptions']) || !is_array($_FILES['prescriptions']['name'])) {
    echo json_encode(['ok' => false, 'error' => 'No files were uploaded.']);
    exit;
}

$cfg = require __DIR__ . '/../../database/ocr_config.php';
$maxBytes = (int) ($cfg['max_file_size'] ?? 1048576);
$allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff', 'pdf'];

$tempDir = __DIR__ . '/../../uploads/ocr_pending';
if (!is_dir($tempDir))
    @mkdir($tempDir, 0775, true);

foreach (glob($tempDir . '/*') as $old) {
    if (is_file($old) && filemtime($old) < time() - 3600)
        @unlink($old);
}

$tokens = [];
$allMatched = [];
$allUnmatched = [];
$allRawText = '';
$fileCount = count($_FILES['prescriptions']['name']);
$processed = 0;

for ($i = 0; $i < $fileCount; $i++) {
    if ((int) $_FILES['prescriptions']['error'][$i] !== UPLOAD_ERR_OK)
        continue;
    if ((int) $_FILES['prescriptions']['size'][$i] > $maxBytes)
        continue;

    $origName = (string) $_FILES['prescriptions']['name'][$i];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true))
        continue;

    $token = bin2hex(random_bytes(16));
    $tempPath = $tempDir . '/' . $token . '.' . $ext;
    if (!move_uploaded_file($_FILES['prescriptions']['tmp_name'][$i], $tempPath))
        continue;

    try {
        $result = ocr_run_full_pipeline($conn, $tempPath, $origName);
        if (!$result['ok']) {
            @unlink($tempPath);
            continue;
        }

        $processed++;
        $tokens[] = ['token' => $token, 'ext' => $ext, 'filename' => $origName];

        foreach (($result['matched'] ?? []) as $m) {
            $key = $m['medicine_id'] . ':' . $m['pharmacy_id'];
            if (!isset($allMatched[$key])) {
                $allMatched[$key] = $m;
                $allMatched[$key]['_sources'] = [$origName];
            } else {
                $allMatched[$key]['_sources'][] = $origName;
            }
        }
        foreach (($result['unmatched'] ?? []) as $line)
            $allUnmatched[] = $line;
        $allRawText .= $result['raw_text'] . "\n\n";
    } catch (Throwable $e) {
        @unlink($tempPath);
        continue;
    }
}

if ($processed === 0) {
    echo json_encode(['ok' => false, 'error' => 'No valid prescription could be processed.']);
    exit;
}

$matchedList = array_values($allMatched);
foreach ($matchedList as &$m) {
    if (!empty($m['_sources'])) {
        $m['source_files'] = $m['_sources'];
        unset($m['_sources']);
    }
}
unset($m);

echo json_encode([
    'ok' => true,
    'tokens' => $tokens,
    'raw_text' => trim($allRawText),
    'matched' => $matchedList,
    'no_stock' => [],
    'unmatched' => array_values(array_unique($allUnmatched)),
    'stats' => [
        'files' => $processed,
        'matched' => count($matchedList),
        'unmatched' => count(array_unique($allUnmatched)),
    ],
]);
exit;