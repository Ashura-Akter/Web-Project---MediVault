<?php
/**
 * MediVault - Pharmacist module
 * File    : includes/post/pharmacist_post_reservation_actions.php
 * Title   : POST handler: approve / reject / accept reservations
 *
 * BACKEND (PHP + SQL)
 * Form field: action_type = APPROVED | REJECTED | RESERVED | CANCELLED.
 * APPROVED  : Pending tab, approves a prescription request, adds the medicine to the patient's cart.
 * REJECTED  : Pending tab, rejects a request with a reason.
 * RESERVED  : Reservations tab, accepts a normal reservation with a pickup deadline.
 * CANCELLED : only opens the cancel-reason modal (final save: pharmacist_post_confirm_cancellation.php).
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');

// Reservation actions are scoped to this pharmacist's assigned pharmacy.
// Form: buttons named action_type with value APPROVED / REJECTED / RESERVED / CANCELLED.
if (isset($_POST['action_type'])) {
    $res_id = (int)($_POST['reservation_id'] ?? 0);
    $action = $_POST['action_type'] ?? '';
    // reserved_for = hours the medicine is held; pharmacist_pickup_deadline = optional date-time from the deadline picker.
    $reserved_for = (int)($_POST['reserved_for'] ?? 24);
    $pharmacist_pickup_deadline = trim((string)($_POST['pharmacist_pickup_deadline'] ?? ''));
    $tab = $_GET['tab'] ?? 'pending';
    // Only these four actions are accepted (whitelist).
    $allowed_actions = ['APPROVED', 'REJECTED', 'RESERVED', 'CANCELLED'];

    if ($res_id > 0 && in_array($action, $allowed_actions, true)) {
        // CANCELLED only opens the cancel-reason modal (?show_cancel_modal=1).
        // The real update happens in pharmacist_post_confirm_cancellation.php.
        if ($action === 'CANCELLED') {
            header('Location: medivaultpharmasist.php?tab=' . urlencode($tab) . '&show_cancel_modal=1&res_id=' . $res_id);
            exit();
        }

        // Make sure the reservation belongs to this pharmacist's pharmacy.
        // SQL: ownership check - the reservation must belong to THIS pharmacist's pharmacy.
        $checkStmt = $conn->prepare("SELECT reservation_id FROM reservations WHERE reservation_id = ? AND pharmacy_id = ? LIMIT 1");
        $checkStmt->bind_param('ii', $res_id, $pharmacyId);
        $checkStmt->execute();
        if (!$checkStmt->get_result()->fetch_assoc()) {
            header('Location: medivaultpharmasist.php?tab=' . urlencode($tab));
            exit();
        }

        // ACTION = APPROVED (Pending tab): approve a prescription / restricted-medicine request.
        if ($action === 'APPROVED') {
            // SQL: set status = APPROVED and store which pharmacist accepted it and when.
            $stmt = $conn->prepare(
                "UPDATE reservations
                 SET status = 'APPROVED',
                     accepted_by_pharmacist_id = ?,
                     accepted_at = NOW(),
                     reserved_for_hours = ?
                 WHERE reservation_id = ? AND pharmacy_id = ?"
            );
            $stmt->bind_param('iiii', $pharmacistId, $reserved_for, $res_id, $pharmacyId);
            $stmt->execute();

            // If this Pending request came from a prescription upload,
            // approve the prescription and notify the patient.
            // SQL: does this reservation come from an uploaded prescription? Read prescription_id and patient_id.
            $rxStmt = $conn->prepare(
                "SELECT prescription_id, patient_id FROM reservations WHERE reservation_id = ? AND pharmacy_id = ? LIMIT 1"
            );
            $rxStmt->bind_param('ii', $res_id, $pharmacyId);
            $rxStmt->execute();
            $rxRow = $rxStmt->get_result()->fetch_assoc();
            if ($rxRow && !empty($rxRow['prescription_id'])) {
                $prescriptionId = (int)$rxRow['prescription_id'];
                $patientId = (int)$rxRow['patient_id'];
                // SQL: mark the linked prescription APPROVED (reviewer, time, note). patient_id guards against mismatches.
                $updateRx = $conn->prepare(
                    "UPDATE prescriptions
                     SET status = 'APPROVED', reviewed_by_pharmacist_id = ?, reviewed_at = NOW(), pharmacist_note = ?
                     WHERE prescription_id = ? AND patient_id = ?"
                );
                $pharmacistNote = trim((string)($_POST['pharmacist_note'] ?? ''));
                $updateRx->bind_param('isii', $pharmacistId, $pharmacistNote, $prescriptionId, $patientId);
                $updateRx->execute();

                // Once a restricted-medicine request is approved, put the exact
                // requested medicine(s) into the patient's active cart at this pharmacy.
                // SQL: read the requested medicines and quantities of this reservation.
                $itemStmt = $conn->prepare(
                    "SELECT medicine_id, quantity FROM reservation_items WHERE reservation_id = ?"
                );
                $itemStmt->bind_param('i', $res_id);
                $itemStmt->execute();
                $approvedItems = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                // Put every approved medicine into the patient's cart (helper in database/queries.php).
                foreach ($approvedItems as $approvedItem) {
                    add_restricted_medicine_to_cart(
                        $conn,
                        $patientId,
                        $pharmacyId,
                        (int)$approvedItem['medicine_id'],
                        (int)$approvedItem['quantity']
                    );
                }

                // Notify the patient (INSERT into notifications through add_notification()).
                add_notification(
                    $conn,
                    $patientId,
                    'PRESCRIPTION_APPROVED',
                    'Restricted Medicine Approved',
                    'Your restricted medicine request has been approved. You can now purchase this restricted medicine. The medicine has already been added to your cart.',
                    null,
                    $res_id,
                    $prescriptionId
                );
            }

            // Restricted-prescription approval and normal reservation approval
            // must show different confirmation messages.
            $popupType = ($rxRow && !empty($rxRow['prescription_id'])) ? 'restricted' : 'reservation';
            header('Location: medivaultpharmasist.php?tab=approved&reservation_accepted=1&approval_type=' . $popupType);
            exit();
        }

        // ACTION = REJECTED (a reason is required).
        if ($action === 'REJECTED') {
            // A rejection reason is required (values match reservations.cancellation_reason).
            // Allowed rejection reasons: key = value stored in the DB, text = label shown to the patient.
            $reject_labels = [
                'PRESCRIPTION_NOT_VALID'    => 'Prescription not valid or unclear',
                'PRESCRIPTION_REQUIRED'     => 'Correct prescription required',
                'DRUG_INTERACTION_DETECTED' => 'Drug interaction detected',
                'OUT_OF_STOCK'              => 'Medicine out of stock',
                'DUPLICATE_RESERVATION'     => 'Duplicate request',
                'OTHER'                     => 'Other',
            ];
            $reject_reason = (string)($_POST['reject_reason'] ?? '');
            // Missing or unknown reason: ignore the request and go back to the Pending tab.
            if (!isset($reject_labels[$reject_reason])) {
                header('Location: medivaultpharmasist.php?tab=pending');
                exit();
            }
            $reject_extra = trim((string)($_POST['pharmacist_note'] ?? ''));
            if ($reject_reason === 'OTHER' && $reject_extra === '') {
                header('Location: medivaultpharmasist.php?tab=pending');
                exit();
            }
            // Stored note = reason label + optional extra text, cut to 500 characters (column length).
            $reject_note = $reject_labels[$reject_reason] . ($reject_extra !== '' ? ': ' . $reject_extra : '');
            $reject_note = function_exists('mb_substr') ? mb_substr($reject_note, 0, 500) : substr($reject_note, 0, 500);

            // SQL: set status = REJECTED and save the reason code and note.
            $stmt = $conn->prepare("UPDATE reservations SET status = 'REJECTED', accepted_by_pharmacist_id = ?, cancellation_reason = ?, cancellation_note = ? WHERE reservation_id = ? AND pharmacy_id = ?");
            $stmt->bind_param('issii', $pharmacistId, $reject_reason, $reject_note, $res_id, $pharmacyId);
            $stmt->execute();

            // SQL: re-read prescription_id and patient_id to know whom to notify.
            $rxStmt = $conn->prepare(
                "SELECT prescription_id, patient_id FROM reservations WHERE reservation_id = ? AND pharmacy_id = ? LIMIT 1"
            );
            $rxStmt->bind_param('ii', $res_id, $pharmacyId);
            $rxStmt->execute();
            $rxRow = $rxStmt->get_result()->fetch_assoc();
            if ($rxRow && !empty($rxRow['prescription_id'])) {
                $prescriptionId = (int)$rxRow['prescription_id'];
                $patientId = (int)$rxRow['patient_id'];
                // SQL: mark the linked prescription REJECTED and save the note.
                $updateRx = $conn->prepare(
                    "UPDATE prescriptions
                     SET status = 'REJECTED', reviewed_by_pharmacist_id = ?, reviewed_at = NOW(), pharmacist_note = ?
                     WHERE prescription_id = ? AND patient_id = ?"
                );
                $pharmacistNote = $reject_note;
                $updateRx->bind_param('isii', $pharmacistId, $pharmacistNote, $prescriptionId, $patientId);
                $updateRx->execute();

                // Notify the patient that the prescription was rejected.
                add_notification(
                    $conn,
                    $patientId,
                    'PRESCRIPTION_REJECTED',
                    'Prescription Rejected',
                    'Your prescription request was rejected by the pharmacy.' . ($pharmacistNote !== '' ? ' Reason: ' . $pharmacistNote : ''),
                    null,
                    $res_id,
                    $prescriptionId
                );
            } else if ($rxRow) {
                // No prescription attached = normal reservation: send a 'Reservation Rejected' notification.
                add_notification(
                    $conn,
                    (int)$rxRow['patient_id'],
                    'RESERVATION_CANCELLED',
                    'Reservation Rejected',
                    'Your reservation was rejected by the pharmacy.' . ($reject_note !== '' ? ' Reason: ' . $reject_note : ''),
                    null,
                    $res_id,
                    null
                );
            }

            // Rejected requests now have their own tab.
            header('Location: medivaultpharmasist.php?tab=rejected');
            exit();
        }

        // ACTION = RESERVED (Reservations tab): accept a normal reservation and hold the stock until a deadline.
        if ($action === 'RESERVED') {
            // SQL: status = ACCEPTED + accepted_by / accepted_at + reserved_for_hours + pickup_deadline.
            $stmt = $conn->prepare(
                "UPDATE reservations
                 SET status = 'ACCEPTED',
                     accepted_by_pharmacist_id = ?,
                     accepted_at = NOW(),
                     reserved_for_hours = ?,
                     pickup_deadline = ?
                 WHERE reservation_id = ? AND pharmacy_id = ?"
            );
            // Deadline = the chosen date-time, otherwise now + reserved_for hours.
            $deadlineValue = $pharmacist_pickup_deadline !== '' ? date('Y-m-d H:i:s', strtotime($pharmacist_pickup_deadline)) : date('Y-m-d H:i:s', time()+($reserved_for*3600));
            $stmt->bind_param('iisii', $pharmacistId, $reserved_for, $deadlineValue, $res_id, $pharmacyId);
            $stmt->execute();

            // SQL: read patient_id for the notification.
            $patientStmt = $conn->prepare("SELECT patient_id FROM reservations WHERE reservation_id = ? AND pharmacy_id = ? LIMIT 1");
            $patientStmt->bind_param('ii', $res_id, $pharmacyId);
            $patientStmt->execute();
            $patientRow = $patientStmt->get_result()->fetch_assoc();
            if ($patientRow) {
                // Notify the patient that the reservation was accepted.
                add_notification(
                    $conn,
                    (int)$patientRow['patient_id'],
                    'RESERVATION_ACCEPTED',
                    'Reservation Accepted',
                    'Your medicine reservation has been accepted. Please check the Request page for the reservation details and pickup deadline.',
                    null,
                    $res_id,
                    null
                );
            }
            // Show the Approved tab with the 'Reservation Accepted' popup.
            header('Location: medivaultpharmasist.php?tab=approved&reservation_accepted=1&approval_type=reservation');
            exit();
        }
    }
}
