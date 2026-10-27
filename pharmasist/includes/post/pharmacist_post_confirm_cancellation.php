<?php
/**
 * MediVault - Pharmacist module
 * File    : includes/post/pharmacist_post_confirm_cancellation.php
 * Title   : POST handler: confirm cancellation of a reservation
 *
 * BACKEND (PHP + SQL)
 * Form field: confirm_cancellation (modal views/modals/pharmacist_modal_cancel_reservation.php).
 * Sets the reservation to CANCELLED, saves the reason and notifies the patient.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');

// Final Submission of Cancellation Reason
// Form: the cancel modal posts reservation_id, cancel_reason and cancellation_note.
if (isset($_POST['confirm_cancellation'])) {
    $res_id = (int)($_POST['reservation_id'] ?? 0);
    $cancel_reason = $_POST['cancel_reason'] ?? 'OTHER';
    $cancel_note = trim($_POST['cancellation_note'] ?? '');
    // Allowed cancellation reasons (whitelist, same values as reservations.cancellation_reason).
    $valid_reasons = [
        'OUT_OF_STOCK',
        'DRUG_INTERACTION_DETECTED',
        'PRESCRIPTION_REQUIRED',
        'PRESCRIPTION_NOT_VALID',
        'DUPLICATE_RESERVATION',
        'OTHER'
    ];
    // Unknown reason: fall back to OTHER.
    if (!in_array($cancel_reason, $valid_reasons, true)) {
        $cancel_reason = 'OTHER';
    }
    // 'Other' requires a written note.
    if ($cancel_reason === 'OTHER' && $cancel_note === '') {
        header("Location: medivaultpharmasist.php?tab=res_history");
        exit();
    }

    // SQL: set status = CANCELLED and save reason + note (only for a reservation of this pharmacy).
    $stmt = $conn->prepare(
        "UPDATE reservations
         SET status = 'CANCELLED',
             cancellation_reason = ?,
             cancellation_note = ?
         WHERE reservation_id = ? AND pharmacy_id = ?"
    );
    $stmt->bind_param('ssii', $cancel_reason, $cancel_note, $res_id, $pharmacyId);
    $stmt->execute();

    // SQL: read patient_id so the patient can be notified.
    $patientStmt = $conn->prepare("SELECT patient_id FROM reservations WHERE reservation_id = ? AND pharmacy_id = ? LIMIT 1");
    $patientStmt->bind_param('ii', $res_id, $pharmacyId);
    $patientStmt->execute();
    $patientRow = $patientStmt->get_result()->fetch_assoc();
    if ($patientRow) {
        // Notify the patient that the reservation was cancelled.
        add_notification(
            $conn,
            (int)$patientRow['patient_id'],
            'RESERVATION_CANCELLED',
            'Reservation Cancelled',
            'Your medicine reservation was cancelled.' . ($cancel_note !== '' ? ' Reason: ' . $cancel_note : ''),
            null,
            $res_id,
            null
        );
    }
    // Show the Reservation History tab.
    header("Location: medivaultpharmasist.php?tab=res_history");
    exit();
}
