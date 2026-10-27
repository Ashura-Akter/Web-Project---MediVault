<?php
session_start();
require_once __DIR__ . '/../../database/queries.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login/login.php');
    exit;
}
$userId = (int) $_SESSION['user_id'];
ensure_checkout_schema($conn);

/* ------------------------------------------------------------------
 * Fire-and-forget background trigger for the OCR auto-worker.
 * Runs the worker immediately (with force=1) so the patient does not
 * have to wait for the next 4-minute tick, and it does NOT require
 * any pharmacist to be logged in.
 * ------------------------------------------------------------------ */
function mv_trigger_ocr_worker_background(): void
{
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    /* From /patient/actions/ocr_buy.php → project root is three levels up. */
    $script = $_SERVER['SCRIPT_NAME'] ?? '/MediVault/patient/actions/ocr_buy.php';
    $base = rtrim(dirname(dirname(dirname($script))), '/\\');
    $url = $proto . '://' . $host . $base . '/pharmasist/auto_ocr_worker.php?force=1';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 1,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_NOSIGNAL => 1,
        ]);
        @curl_exec($ch);
        curl_close($ch);
    } else {
        /* Fallback: stream with a 1-second timeout. */
        $ctx = stream_context_create(['http' => ['timeout' => 1]]);
        @file_get_contents($url, false, $ctx);
    }
}

$rawItems = $_POST['items'] ?? [];
if (!is_array($rawItems) || !$rawItems) {
    $_SESSION['checkout_error'] = 'No medicines were selected.';
    header('Location: ../ocr_prescription.php');
    exit;
}

/* ------------------------------------------------------------------
 * Multiple prescription tokens (JSON array).
 * Fallback to the single-token field for backwards compatibility.
 * ------------------------------------------------------------------ */
$tokens = [];
$rawTokens = trim((string) ($_POST['prescription_tokens'] ?? ''));
if ($rawTokens !== '') {
    $decoded = json_decode($rawTokens, true);
    if (is_array($decoded)) {
        foreach ($decoded as $t) {
            $t = trim((string) $t);
            if (preg_match('/^[a-f0-9]{32}$/', $t))
                $tokens[] = $t;
        }
    }
}
if (!$tokens) {
    $single = trim((string) ($_POST['prescription_token'] ?? ''));
    if (preg_match('/^[a-f0-9]{32}$/', $single))
        $tokens[] = $single;
}

/* ------------------------------------------------------------------
 * Validate the incoming items and split into normal vs restricted.
 * ------------------------------------------------------------------ */
$parsed = [];
foreach ($rawItems as $raw) {
    $parts = explode(':', (string) $raw);
    if (count($parts) !== 3)
        continue;
    $mid = (int) $parts[0];
    $pid = (int) $parts[1];
    $qty = max(1, (int) $parts[2]);
    if ($mid <= 0 || $pid <= 0)
        continue;

    $chk = $conn->prepare(
        "SELECT m.medicine_id, m.medicine_name, m.is_restricted, m.requires_prescription,
                m.is_active, p.pharmacy_id, p.is_active AS ph_active
         FROM medicines m
         JOIN pharmacies p ON p.pharmacy_id = ?
         WHERE m.medicine_id = ? LIMIT 1"
    );
    $chk->bind_param('ii', $pid, $mid);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    if (!$row || (int) $row['is_active'] !== 1 || (int) $row['ph_active'] !== 1)
        continue;

    $parsed[] = [
        'medicine_id' => $mid,
        'medicine_name' => (string) $row['medicine_name'],
        'pharmacy_id' => $pid,
        'qty' => $qty,
        'is_restricted' => (int) $row['is_restricted'] === 1,
        'requires_rx' => (int) $row['requires_prescription'] === 1,
    ];
}
if (!$parsed) {
    $_SESSION['checkout_error'] = 'The selected medicines are no longer available.';
    header('Location: ../ocr_prescription.php');
    exit;
}

$normalItems = [];
$restrictedItems = [];
foreach ($parsed as $p) {
    if ($p['is_restricted'] || $p['requires_rx'])
        $restrictedItems[] = $p;
    else
        $normalItems[] = $p;
}

/* ------------------------------------------------------------------
 * STEP 1 — Normal items → cart.
 * ------------------------------------------------------------------ */
$normalCartIds = [];
foreach ($normalItems as $item) {
    if (!add_to_cart($conn, $userId, $item['medicine_id'], $item['qty'], $item['pharmacy_id']))
        continue;
    $stmt = $conn->prepare(
        "SELECT ci.cart_item_id
         FROM cart_items ci
         JOIN carts c ON c.cart_id = ci.cart_id
         WHERE c.user_id = ? AND c.status = 'ACTIVE'
           AND ci.medicine_id = ? AND ci.pharmacy_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('iii', $userId, $item['medicine_id'], $item['pharmacy_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row)
        $normalCartIds[] = (int) $row['cart_item_id'];
}

/* ------------------------------------------------------------------
 * STEP 2 — Restricted items → reservation for pharmacist verification.
 * ------------------------------------------------------------------ */
if ($restrictedItems) {
    if (!$tokens) {
        $_SESSION['checkout_error'] = 'Prescription file is missing. Please re-scan.';
        header('Location: ../ocr_prescription.php');
        exit;
    }

    $tempDir = __DIR__ . '/../../uploads/ocr_pending';
    $prescDir = __DIR__ . '/../../uploads/prescriptions';
    if (!is_dir($prescDir))
        @mkdir($prescDir, 0775, true);

    /* Move each uploaded prescription file into the permanent folder. */
    $groupRxFiles = [];
    foreach ($tokens as $token) {
        $src = null;
        $ext = 'jpg';
        foreach (['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff', 'pdf'] as $e) {
            $cand = $tempDir . '/' . $token . '.' . $e;
            if (is_file($cand)) {
                $src = $cand;
                $ext = $e;
                break;
            }
        }
        if (!$src)
            continue;
        $base = 'RX-' . date('YmdHis') . '-' . random_int(100, 999) . '-' . substr($token, 0, 6);
        $destName = $base . '.' . $ext;
        $destPath = $prescDir . '/' . $destName;
        if (!@rename($src, $destPath)) {
            if (!@copy($src, $destPath))
                continue;
            @unlink($src);
        }
        $groupRxFiles[] = [
            'path' => 'uploads/prescriptions/' . $destName,
            'type' => ($ext === 'pdf') ? 'PDF' : 'IMAGE',
        ];
    }
    if (!$groupRxFiles) {
        $_SESSION['checkout_error'] = 'Could not save the prescription files.';
        header('Location: ../ocr_prescription.php');
        exit;
    }

    $primaryRx = $groupRxFiles[0];

    /* Group restricted items by pharmacy so each pharmacy gets its own request. */
    $groups = [];
    foreach ($restrictedItems as $p) {
        $pid = (int) $p['pharmacy_id'];
        if ($pid <= 0)
            continue;
        $groups[$pid][] = $p;
    }

    $conn->begin_transaction();
    try {
        $createdReservations = [];

        foreach ($groups as $pharmacyId => $groupItems) {
            /* Primary prescription row. */
            $prescNo = 'RX-' . date('YmdHis') . '-' . random_int(100, 999) . '-P' . $pharmacyId;
            $stmt = $conn->prepare(
                "INSERT INTO prescriptions (patient_id, prescription_number, file_path, file_type, status, uploaded_at)
                 VALUES (?, ?, ?, ?, 'PENDING_REVIEW', NOW())"
            );
            $stmt->bind_param('isss', $userId, $prescNo, $primaryRx['path'], $primaryRx['type']);
            $stmt->execute();
            $prescriptionId = (int) $conn->insert_id;

            /* Prescription items (medicines the patient wants to buy). */
            foreach ($groupItems as $gi) {
                $ins = $conn->prepare(
                    "INSERT INTO prescription_items (prescription_id, medicine_id, prescribed_quantity, dosage_instruction)
                     VALUES (?, ?, ?, 'Use according to prescription.')"
                );
                $q = (int) $gi['qty'];
                $ins->bind_param('iii', $prescriptionId, $gi['medicine_id'], $q);
                $ins->execute();
            }

            /* Reservation row. */
            $resNo = 'RXREQ-' . date('YmdHis') . '-' . random_int(100, 999) . '-P' . $pharmacyId;
            $resStmt = $conn->prepare(
                "INSERT INTO reservations
                    (patient_id, pharmacy_id, prescription_id, reservation_number, status, reserved_for_hours, requested_at)
                 VALUES (?, ?, ?, ?, 'AWAITING_RX', 24, NOW())"
            );
            $resStmt->bind_param('iiis', $userId, $pharmacyId, $prescriptionId, $resNo);
            $resStmt->execute();
            $reservationId = (int) $conn->insert_id;

            /* Reservation items. */
            foreach ($groupItems as $gi) {
                $ri = $conn->prepare(
                    "INSERT INTO reservation_items (reservation_id, medicine_id, quantity) VALUES (?, ?, ?)"
                );
                $ri->bind_param('iii', $reservationId, $gi['medicine_id'], $gi['qty']);
                $ri->execute();
            }

            /* Additional prescription files → extra prescriptions rows
               linked to the same reservation so the pharmacist can review
               all uploaded pages together. */
            foreach (array_slice($groupRxFiles, 1) as $extra) {
                $extraNo = $prescNo . '-X' . random_int(10, 99);
                $exStmt = $conn->prepare(
                    "INSERT INTO prescriptions (patient_id, prescription_number, file_path, file_type, status, uploaded_at)
                     VALUES (?, ?, ?, ?, 'PENDING_REVIEW', NOW())"
                );
                $exStmt->bind_param('isss', $userId, $extraNo, $extra['path'], $extra['type']);
                $exStmt->execute();
                $extraId = (int) $conn->insert_id;

                foreach ($groupItems as $gi) {
                    $ins = $conn->prepare(
                        "INSERT INTO prescription_items (prescription_id, medicine_id, prescribed_quantity, dosage_instruction)
                         VALUES (?, ?, ?, 'Attached from multi-file upload.')"
                    );
                    $q = (int) $gi['qty'];
                    $ins->bind_param('iii', $extraId, $gi['medicine_id'], $q);
                    $ins->execute();
                }
            }

            /* Notify pharmacist staff + patient. */
            notify_pharmacy_staff(
                $conn,
                $pharmacyId,
                'PRESCRIPTION_UPLOADED',
                'New Restricted Medicine Request',
                'A patient submitted a restricted-medicine request with ' . count($groupRxFiles) . ' prescription file(s). Please review.',
                null,
                $reservationId,
                $prescriptionId
            );

            add_notification(
                $conn,
                $userId,
                'PRESCRIPTION_UPLOADED',
                'Restricted Order Pending Approval',
                'Your restricted medicine request ' . $resNo . ' is awaiting pharmacist verification (usually 10–20 minutes).',
                null,
                $reservationId,
                $prescriptionId
            );

            $createdReservations[] = [
                'reservation_id' => $reservationId,
                'reservation_number' => $resNo,
            ];
        }

        $conn->commit();

        /* Fire-and-forget: run the OCR worker NOW so the patient does not
           have to wait for the next 4-minute tick, and so the whole flow
           works without any pharmacist being logged in. */
        mv_trigger_ocr_worker_background();

        $_SESSION['checkout_selected_ids'] = $normalCartIds;
        $_SESSION['ocr_pending_approval'] = [
            'reservations' => $createdReservations,
            'wait_min' => 10,
            'wait_max' => 20,
            'normal_items' => count($normalItems),
            'cart_ids' => $normalCartIds,
        ];
        header('Location: ../requests.php?pending_approval=1');
        exit;
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('OCR restricted buy error: ' . $e->getMessage());
        $_SESSION['checkout_error'] = 'Could not create the request. Please try again.';
        header('Location: ../ocr_prescription.php');
        exit;
    }
}

/* ------------------------------------------------------------------
 * STEP 3 — No restricted items → straight to checkout with the
 *          cart items the OCR flow just added.
 * ------------------------------------------------------------------ */
$_SESSION['checkout_selected_ids'] = $normalCartIds;
header('Location: ../checkout.php');
exit;