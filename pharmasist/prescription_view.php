<?php
session_start();
require_once __DIR__ . '/../database/queries.php';
require_once __DIR__ . '/../database/ocr_helper.php';

if (!isset($_SESSION['user_id']) || strtoupper($_SESSION['role'] ?? '') !== 'PHARMACIST') {
    http_response_code(403);
    exit('Access denied.');
}

$pharmacistId  = (int)($_SESSION['user_id']);
$reservationId = (int)($_GET['reservation_id'] ?? 0);
if ($reservationId <= 0) { http_response_code(400); exit('Invalid request.'); }

/* ---- Load prescription + reservation + pharmacy scope ---- */
$stmt = $conn->prepare(
    "SELECT pr.prescription_id, pr.file_path, pr.file_type, pr.prescription_number,
            r.reservation_id, r.reservation_number, r.patient_id, r.pharmacy_id,
            r.status AS reservation_status,
            p.pharmacy_name
     FROM reservations r
     JOIN pharmacist_profiles pp ON pp.pharmacy_id = r.pharmacy_id
                              AND pp.user_id = ?
                              AND pp.approval_status = 'APPROVED'
     JOIN prescriptions pr ON pr.prescription_id = r.prescription_id
     JOIN pharmacies p ON p.pharmacy_id = r.pharmacy_id
     WHERE r.reservation_id = ? AND r.prescription_id IS NOT NULL
     LIMIT 1"
);
$stmt->bind_param('ii', $pharmacistId, $reservationId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) { http_response_code(404); exit('Prescription not found.'); }

$prescriptionId   = (int)$row['prescription_id'];
$patientId        = (int)$row['patient_id'];
$pharmacyId       = (int)$row['pharmacy_id'];
$reservationNo    = (string)$row['reservation_number'];
$reservationState = (string)$row['reservation_status'];

/* ---- Resolve the file safely ---- */
$base = realpath(__DIR__ . '/../uploads/prescriptions');
$file = false;
if ($base) {
    $storedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)$row['file_path']);
    $candidate = realpath(__DIR__ . '/../' . ltrim($storedPath, DIRECTORY_SEPARATOR));
    if ($candidate && str_starts_with($candidate, $base . DIRECTORY_SEPARATOR) && is_file($candidate)) {
        $file = $candidate;
    } else {
        $candidate = realpath($base . DIRECTORY_SEPARATOR . basename($storedPath));
        if ($candidate && str_starts_with($candidate, $base . DIRECTORY_SEPARATOR) && is_file($candidate)) {
            $file = $candidate;
        }
    }
}
if (!$file) { http_response_code(404); exit('Prescription file not found.'); }

$mime = function_exists('mime_content_type') ? mime_content_type($file) : '';
if (!$mime) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = $ext === 'pdf' ? 'application/pdf' : ($ext === 'png' ? 'image/png' : 'image/jpeg');
}
if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
    http_response_code(415);
    exit('Unsupported prescription file.');
}

/* ---- If the browser requested the raw file, stream it and stop ---- */
if (isset($_GET['raw'])) {
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . basename($file) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}

/* ---- Run OCR live so the pharmacist sees what the system detected ---- */
$ocrText    = '';
$ocrOk      = false;
$ocrError   = '';
$matched    = [];
$matchedIds = [];
try {
    $ocrResult = ocr_extract_text_from_file($file, basename($file));
    if (!empty($ocrResult['ok'])) {
        $ocrOk   = true;
        $ocrText = (string)($ocrResult['text'] ?? '');
        $match   = ocr_match_medicines($conn, $ocrText);
        $matched = $match['matched'] ?? [];
        $matchedIds = array_map(fn($m) => (int)$m['medicine_id'], $matched);
    } else {
        $ocrError = (string)($ocrResult['error'] ?? 'OCR engine could not read this file.');
    }
} catch (Throwable $e) {
    $ocrError = 'OCR failed: ' . $e->getMessage();
}

/* ---- What this reservation actually requests ---- */
$reqStmt = $conn->prepare(
    "SELECT ri.medicine_id, ri.quantity, m.medicine_name, m.generic_name, m.strength
     FROM reservation_items ri
     JOIN medicines m ON m.medicine_id = ri.medicine_id
     WHERE ri.reservation_id = ?"
);
$reqStmt->bind_param('i', $reservationId);
$reqStmt->execute();
$required = $reqStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$requiredIds     = array_map(fn($r) => (int)$r['medicine_id'], $required);
$foundRequired   = [];
$missingRequired = [];
foreach ($required as $r) {
    if (in_array((int)$r['medicine_id'], $matchedIds, true)) {
        $foundRequired[] = $r;
    } else {
        $missingRequired[] = $r;
    }
}
$extraMatched = [];
foreach ($matched as $m) {
    if (!in_array((int)$m['medicine_id'], $requiredIds, true)) {
        $extraMatched[] = $m;
    }
}

$canAct = in_array($reservationState, ['PENDING', 'AWAITING_RX'], true);

$viewerSelf = basename(__FILE__);
$previewUrl = $viewerSelf . '?reservation_id=' . $reservationId . '&raw=1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Prescription — <?= htmlspecialchars($reservationNo) ?></title>
<style>
    * { box-sizing: border-box; }
    html, body {
        margin: 0; padding: 0; height: 100%;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        background: #0f172a;
        color: #e2e8f0;
    }
    .rx-wrap {
        display: grid;
        grid-template-columns: 1fr 360px;
        height: 100vh;
        gap: 0;
    }
    .rx-left {
        background: #111827;
        padding: 16px;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }
    .rx-left iframe {
        flex: 1; width: 100%; border: 0; border-radius: 10px; background: #fff;
    }
    .rx-right {
        background: #1e293b;
        padding: 20px;
        overflow-y: auto;
        border-left: 1px solid #334155;
        display: flex;
        flex-direction: column;
        gap: 16px;
    }
    .rx-head { font-size: 13px; color: #94a3b8; margin-bottom: 4px; }
    .rx-title {
        font-size: 17px; font-weight: 800; color: #f1f5f9;
        margin-bottom: 14px; word-break: break-all;
    }
    .rx-section-title {
        font-size: 12px; letter-spacing: .06em; text-transform: uppercase;
        color: #94a3b8; font-weight: 800; margin-bottom: 8px;
    }
    .rx-item {
        display: flex; align-items: flex-start; gap: 10px;
        background: #0f172a; border: 1px solid #334155;
        padding: 10px 12px; border-radius: 10px; margin-bottom: 8px;
        font-size: 13.5px; line-height: 1.35;
    }
    .rx-item .tick { font-size: 15px; line-height: 1.2; margin-top: 1px; }
    .rx-item.ok    { border-color: #065f46; background: #052e22; }
    .rx-item.miss  { border-color: #7f1d1d; background: #2a0f0f; }
    .rx-item.extra { border-color: #78350f; background: #2a1a06; }
    .rx-item b { color: #f8fafc; }
    .rx-item small { color: #94a3b8; display: block; margin-top: 2px; }

    .rx-badge {
        display: inline-block; font-size: 11px; font-weight: 800;
        padding: 3px 9px; border-radius: 20px; letter-spacing: .02em;
    }
    .rx-badge.ok   { background: #065f46; color: #d1fae5; }
    .rx-badge.miss { background: #7f1d1d; color: #fecaca; }
    .rx-badge.warn { background: #78350f; color: #fde68a; }
    .rx-badge.info { background: #1e40af; color: #dbeafe; }

    .rx-error {
        background: #2a0f0f; border: 1px solid #7f1d1d; color: #fecaca;
        padding: 10px 12px; border-radius: 10px; font-size: 13px;
    }

    details.rx-raw {
        background: #0f172a; border: 1px solid #334155; border-radius: 10px;
        padding: 10px 12px; font-size: 12.5px; color: #cbd5e1;
    }
    details.rx-raw summary {
        cursor: pointer; font-weight: 700; color: #e2e8f0;
        outline: none; user-select: none;
    }
    details.rx-raw pre {
        white-space: pre-wrap; word-break: break-word;
        margin: 10px 0 0; max-height: 220px; overflow: auto;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 11.5px; color: #cbd5e1;
    }

    .rx-note {
        font-size: 12px; color: #94a3b8; text-align: center;
        margin-top: auto;
        padding-top: 8px;
    }
    @media (max-width: 880px) {
        .rx-wrap { grid-template-columns: 1fr; grid-template-rows: 55vh auto; height: auto; }
        .rx-left { height: 55vh; }
        .rx-right { border-left: 0; border-top: 1px solid #334155; }
    }
</style>
</head>
<body>

<div class="rx-wrap">

    <!-- LEFT: the actual prescription -->
    <div class="rx-left">
        <iframe id="rxFrame" title="Uploaded prescription" src="<?= htmlspecialchars($previewUrl) ?>"></iframe>
    </div>

    <!-- RIGHT: OCR result -->
    <div class="rx-right">
        <div>
            <div class="rx-head">Reservation</div>
            <div class="rx-title"><?= htmlspecialchars($reservationNo) ?></div>
            <div>
                <?php if ($ocrOk): ?>
                    <span class="rx-badge info">OCR ran</span>
                <?php elseif ($ocrError !== ''): ?>
                    <span class="rx-badge warn">OCR unavailable</span>
                <?php endif; ?>
                <?php if ($canAct): ?>
                    <span class="rx-badge info" style="margin-left:6px;">Awaiting decision</span>
                <?php else: ?>
                    <span class="rx-badge warn" style="margin-left:6px;"><?= htmlspecialchars($reservationState) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($ocrError !== ''): ?>
            <div class="rx-error">
                <?= htmlspecialchars($ocrError) ?><br>
                <small>You can still review the prescription image and decide from the Pending tab.</small>
            </div>
        <?php endif; ?>

        <?php if (!empty($foundRequired)): ?>
            <div>
                <div class="rx-section-title">Detected in prescription ✔</div>
                <?php foreach ($foundRequired as $m): ?>
                    <div class="rx-item ok">
                        <span class="tick">✅</span>
                        <div>
                            <b><?= htmlspecialchars($m['medicine_name']) ?></b>
                            <?php if (!empty($m['generic_name']) || !empty($m['strength'])): ?>
                                <small><?= htmlspecialchars(trim(($m['generic_name'] ?? '') . ' ' . ($m['strength'] ?? ''))) ?></small>
                            <?php endif; ?>
                            <small>Qty requested: <?= (int)$m['quantity'] ?></small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($missingRequired)): ?>
            <div>
                <div class="rx-section-title">NOT found in prescription ✘</div>
                <?php foreach ($missingRequired as $m): ?>
                    <div class="rx-item miss">
                        <span class="tick">❌</span>
                        <div>
                            <b><?= htmlspecialchars($m['medicine_name']) ?></b>
                            <?php if (!empty($m['generic_name']) || !empty($m['strength'])): ?>
                                <small><?= htmlspecialchars(trim(($m['generic_name'] ?? '') . ' ' . ($m['strength'] ?? ''))) ?></small>
                            <?php endif; ?>
                            <small>Requested but the OCR could not find it.</small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($extraMatched)): ?>
            <div>
                <div class="rx-section-title">Other medicines seen (not requested)</div>
                <?php foreach ($extraMatched as $m): ?>
                    <div class="rx-item extra">
                        <span class="tick">⚠️</span>
                        <div>
                            <b><?= htmlspecialchars($m['medicine_name'] ?? ('Medicine #' . (int)$m['medicine_id'])) ?></b>
                            <small>Detected in the image but not part of this request.</small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($ocrOk && empty($foundRequired) && empty($missingRequired) && empty($extraMatched)): ?>
            <div class="rx-error">
                OCR ran but no known medicines were recognised. Please review the image manually.
            </div>
        <?php endif; ?>

        <?php if ($ocrOk && $ocrText !== ''): ?>
            <details class="rx-raw">
                <summary>View raw OCR text</summary>
                <pre><?= htmlspecialchars($ocrText) ?></pre>
            </details>
        <?php endif; ?>

        <div class="rx-note">
            Review complete — return to the Pending tab to approve or reject this request.
        </div>
    </div>
</div>

<script>
/*
 * This viewer is read-only. Approve / Reject are handled from the Pending
 * tab in the pharmacist dashboard, so no action messages are posted from here.
 */
</script>
</body>
</html>