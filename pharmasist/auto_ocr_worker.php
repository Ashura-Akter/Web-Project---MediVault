<?php
/*
 * MediVault Auto-OCR Worker
 * -------------------------
 * Runs every ~4 minutes and processes pending restricted-medicine
 * prescriptions submitted via the patient OCR flow.
 *
 * Triggering:
 *  1) Automatically from the pharmacist dashboard on every page load
 *     (rate-limited by a lock file to once per 4 minutes).
 *  2) Manually via CLI:  php pharmasist/auto_ocr_worker.php --force
 *     or via HTTP:       .../pharmasist/auto_ocr_worker.php?force=1
 */
require_once __DIR__ . '/../database/queries.php';
require_once __DIR__ . '/../database/ocr_helper.php';

if (!function_exists('run_auto_ocr_worker')) {
    function run_auto_ocr_worker(mysqli $conn, bool $force = false): array
    {
        $lockFile = __DIR__ . '/../database/.ocr_worker_lock';
        $lastRun  = is_file($lockFile) ? (int)file_get_contents($lockFile) : 0;
        $now      = time();
        $minInterval = 240;

        if (!$force && ($now - $lastRun) < $minInterval) {
            return ['skipped' => true, 'reason' => 'waiting'];
        }
        @file_put_contents($lockFile, $now);

        $stats = ['processed' => 0, 'approved' => 0, 'rejected' => 0, 'kept_pending' => 0, 'errors' => 0];

        $query = "
            SELECT DISTINCT
                p.prescription_id, p.patient_id, p.file_path, p.file_type,
                r.reservation_id, r.reservation_number, r.pharmacy_id,
                ph.pharmacy_name
            FROM prescriptions p
            JOIN reservations r ON r.prescription_id = p.prescription_id
            JOIN pharmacies ph ON ph.pharmacy_id = r.pharmacy_id
            WHERE p.status = 'PENDING_REVIEW'
              AND r.status = 'AWAITING_RX'
            ORDER BY p.uploaded_at ASC
            LIMIT 20
        ";
        $result = $conn->query($query);
        if (!$result) return $stats;

        while ($row = $result->fetch_assoc()) {
            $stats['processed']++;
            $prescriptionId = (int)$row['prescription_id'];
            $reservationId  = (int)$row['reservation_id'];
            $patientId      = (int)$row['patient_id'];
            $pharmacyId     = (int)$row['pharmacy_id'];

            $filePath = __DIR__ . '/../' . ltrim((string)$row['file_path'], '/\\');
            if (!is_file($filePath)) {
                $stats['kept_pending']++;
                continue;
            }

            try {
                $ocrResult = ocr_extract_text_from_file($filePath, basename($filePath));
            } catch (Throwable $e) {
                $stats['errors']++;
                continue;
            }
            if (empty($ocrResult['ok'])) {
                $stats['kept_pending']++;
                continue;
            }

            $matchResult = ocr_match_medicines($conn, (string)$ocrResult['text']);
            $matchedIds  = array_map(fn($m) => (int)$m['medicine_id'], $matchResult['matched']);

            $itemStmt = $conn->prepare(
                "SELECT ri.medicine_id, ri.quantity, m.medicine_name
                 FROM reservation_items ri
                 JOIN medicines m ON m.medicine_id = ri.medicine_id
                 WHERE ri.reservation_id = ?"
            );
            $itemStmt->bind_param('i', $reservationId);
            $itemStmt->execute();
            $required = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);

            if (!$required) {
                $stats['kept_pending']++;
                continue;
            }

            $missingNames = [];
            foreach ($required as $ri) {
                if (!in_array((int)$ri['medicine_id'], $matchedIds, true)) {
                    $missingNames[] = $ri['medicine_name'];
                }
            }

            $stockStmt = $conn->prepare(
                "SELECT COALESCE(SUM(GREATEST(quantity_on_hand - reserved_quantity, 0)), 0) AS avail
                 FROM inventory_batches
                 WHERE pharmacy_id = ? AND medicine_id = ? AND expiry_date > CURDATE()"
            );
            $lowStockNames = [];
            foreach ($required as $ri) {
                $mid = (int)$ri['medicine_id'];
                $stockStmt->bind_param('ii', $pharmacyId, $mid);
                $stockStmt->execute();
                $avail = (int)($stockStmt->get_result()->fetch_assoc()['avail'] ?? 0);
                if ($avail < 1) $lowStockNames[] = $ri['medicine_name'];
            }

            if ($missingNames) {
                $reason = 'Prescription does not include: ' . implode(', ', $missingNames) . '. Please upload a valid prescription.';
                try {
                    $conn->begin_transaction();
                    $upd = $conn->prepare(
                        "UPDATE prescriptions SET status='REJECTED', reviewed_at=NOW(), pharmacist_note=?
                         WHERE prescription_id=? AND status='PENDING_REVIEW'"
                    );
                    $upd->bind_param('si', $reason, $prescriptionId);
                    $upd->execute();

                    $upd = $conn->prepare(
                        "UPDATE reservations SET status='REJECTED',
                             accepted_by_pharmacist_id=NULL,
                             cancellation_reason='PRESCRIPTION_NOT_VALID',
                             cancellation_note=?
                         WHERE reservation_id=? AND status='AWAITING_RX'"
                    );
                    $upd->bind_param('si', $reason, $reservationId);
                    $upd->execute();

                    add_notification(
                        $conn,
                        $patientId,
                        'PRESCRIPTION_REJECTED',
                        'Restricted Medicine Rejected',
                        'Your restricted medicine request ' . $row['reservation_number'] . ' was rejected. Reason: ' . $reason,
                        null,
                        $reservationId,
                        $prescriptionId
                    );
                    $conn->commit();
                    $stats['rejected']++;
                } catch (Throwable $e) {
                    $conn->rollback();
                    $stats['errors']++;
                }
            } elseif ($lowStockNames) {
                $reason = 'Out of stock at ' . $row['pharmacy_name'] . ': ' . implode(', ', $lowStockNames) . '.';
                try {
                    $conn->begin_transaction();
                    $upd = $conn->prepare(
                        "UPDATE prescriptions SET status='REJECTED', reviewed_at=NOW(), pharmacist_note=?
                         WHERE prescription_id=? AND status='PENDING_REVIEW'"
                    );
                    $upd->bind_param('si', $reason, $prescriptionId);
                    $upd->execute();

                    $upd = $conn->prepare(
                        "UPDATE reservations SET status='REJECTED',
                             accepted_by_pharmacist_id=NULL,
                             cancellation_reason='OUT_OF_STOCK',
                             cancellation_note=?
                         WHERE reservation_id=? AND status='AWAITING_RX'"
                    );
                    $upd->bind_param('si', $reason, $reservationId);
                    $upd->execute();

                    add_notification(
                        $conn,
                        $patientId,
                        'PRESCRIPTION_REJECTED',
                        'Restricted Medicine Rejected',
                        'Your restricted medicine request ' . $row['reservation_number'] . ' was rejected. Reason: ' . $reason,
                        null,
                        $reservationId,
                        $prescriptionId
                    );
                    $conn->commit();
                    $stats['rejected']++;
                } catch (Throwable $e) {
                    $conn->rollback();
                    $stats['errors']++;
                }
            } else {
                try {
                    $conn->begin_transaction();
                    $upd = $conn->prepare(
                        "UPDATE prescriptions SET status='APPROVED', reviewed_at=NOW(), pharmacist_note='Auto-approved by OCR system'
                         WHERE prescription_id=? AND status='PENDING_REVIEW'"
                    );
                    $upd->bind_param('i', $prescriptionId);
                    $upd->execute();

                    $upd = $conn->prepare(
                        "UPDATE reservations SET status='APPROVED', accepted_at=NOW()
                         WHERE reservation_id=? AND status='AWAITING_RX'"
                    );
                    $upd->bind_param('i', $reservationId);
                    $upd->execute();

                    foreach ($required as $ri) {
                        add_restricted_medicine_to_cart(
                            $conn,
                            $patientId,
                            $pharmacyId,
                            (int)$ri['medicine_id'],
                            (int)$ri['quantity']
                        );
                    }

                    add_notification(
                        $conn,
                        $patientId,
                        'PRESCRIPTION_APPROVED',
                        'Restricted Medicine Approved',
                        'Great news! Your restricted medicine request ' . $row['reservation_number'] . ' has been approved. The medicines are now in your cart — proceed to checkout.',
                        null,
                        $reservationId,
                        $prescriptionId
                    );
                    $conn->commit();
                    $stats['approved']++;
                } catch (Throwable $e) {
                    $conn->rollback();
                    $stats['errors']++;
                }
            }
        }

        return $stats;
    }
}

$isCli    = (php_sapi_name() === 'cli');
$isDirect = isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__);

if ($isCli || $isDirect) {
    $force = false;
    if ($isCli && isset($argv[1]) && $argv[1] === '--force') $force = true;
    if (!$isCli && isset($_GET['force']) && $_GET['force'] === '1') $force = true;

    $stats = run_auto_ocr_worker($conn, $force);

    if ($isCli) {
        echo json_encode($stats, JSON_PRETTY_PRINT) . "\n";
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($stats);
    }
    exit;
}
