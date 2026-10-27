<?php
session_start();
require_once __DIR__ . '/../database/queries.php';
if (!isset($_SESSION['user_id'])) { header('Location: ../login/login.php'); exit; }

$userId = (int)$_SESSION['user_id'];
$returnUrl = trim((string)($_POST['return_url'] ?? 'browse.php?view=all'));
// Keep redirects inside the patient area only.
if (!preg_match('/^(?:[A-Za-z0-9_-]+\.php)(?:\?[^#]*)?$/', $returnUrl)) {
    $returnUrl = 'browse.php?view=all';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['prescription'])) {
    header('Location: ' . $returnUrl); exit;
}

$medicineId = (int)($_POST['medicine_id'] ?? 0);
$pharmacyId = (int)($_POST['pharmacy_id'] ?? 0);
if ($medicineId <= 0 || $pharmacyId <= 0) {
    $_SESSION['upload_error'] = 'Please select a valid restricted medicine and pharmacy.';
    $_SESSION['upload_return_url'] = $returnUrl;
    header('Location: ' . $returnUrl); exit;
}

// Verify that this medicine is restricted and currently stocked at the selected pharmacy.
$check = $conn->prepare(
    "SELECT m.medicine_id, m.is_restricted
     FROM medicines m
     JOIN inventory_batches ib ON ib.medicine_id = m.medicine_id
     JOIN pharmacies p ON p.pharmacy_id = ib.pharmacy_id
     WHERE m.medicine_id = ? AND ib.pharmacy_id = ? AND m.is_active = TRUE AND p.is_active = TRUE
       AND ib.expiry_date > CURRENT_DATE()
       AND (ib.quantity_on_hand - ib.reserved_quantity) > 0
     LIMIT 1"
);
$check->bind_param('ii', $medicineId, $pharmacyId);
$check->execute();
$validMedicine = $check->get_result()->fetch_assoc();
if (!$validMedicine || (int)$validMedicine['is_restricted'] !== 1) {
    $_SESSION['upload_error'] = 'This restricted medicine is not available at the selected pharmacy.';
    $_SESSION['upload_return_url'] = $returnUrl;
    header('Location: ' . $returnUrl); exit;
}

$file = $_FILES['prescription'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['upload_error'] = 'Prescription upload failed.';
    $_SESSION['upload_return_url'] = $returnUrl;
    header('Location: ' . $returnUrl); exit;
}

$allowed = [
    'image/jpeg' => ['IMAGE','jpg'],
    'image/png' => ['IMAGE','png'],
    'application/pdf' => ['PDF','pdf']
];

$mime = mime_content_type($file['tmp_name']);
if (!isset($allowed[$mime])) {
    $_SESSION['upload_error'] = 'Only JPG, PNG or PDF prescription files are allowed.';
    $_SESSION['upload_return_url'] = $returnUrl;
    header('Location: ' . $returnUrl); exit;
}

$number = 'RX-' . date('YmdHis') . '-' . random_int(100,999);
$extension = $allowed[$mime][1];
$filename = $number . '.' . $extension;
$relative = 'uploads/prescriptions/' . $filename;
$destination = __DIR__ . '/../uploads/prescriptions/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    $_SESSION['upload_error'] = 'Could not save the prescription file.';
    $_SESSION['upload_return_url'] = $returnUrl;
    header('Location: ' . $returnUrl); exit;
}

$status = 'PENDING_REVIEW';
$fileType = $allowed[$mime][0];

$conn->begin_transaction();
try {
    $stmt = $conn->prepare(
        "INSERT INTO prescriptions
         (patient_id, prescription_number, file_path, file_type, status)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('issss', $userId, $number, $relative, $fileType, $status);
    $stmt->execute();
    $prescriptionId = (int)$conn->insert_id;

    $stmt = $conn->prepare(
        "INSERT INTO prescription_items (prescription_id, medicine_id, prescribed_quantity, dosage_instruction)
         VALUES (?, ?, 1, 'Use according to prescription.')"
    );
    $stmt->bind_param('ii', $prescriptionId, $medicineId);
    $stmt->execute();

    // Reuse the existing Pending/Approved pharmacist workflow. The request is
    // tied to the exact pharmacy selected from the medicine card.
    $reservationNumber = 'RXREQ-' . date('YmdHis') . '-' . random_int(100,999);
    $stmt = $conn->prepare(
        "INSERT INTO reservations
         (patient_id, pharmacy_id, prescription_id, reservation_number, status)
         VALUES (?, ?, ?, ?, 'AWAITING_RX')"
    );
    $stmt->bind_param('iiis', $userId, $pharmacyId, $prescriptionId, $reservationNumber);
    $stmt->execute();
    $reservationId = (int)$conn->insert_id;

    $stmt = $conn->prepare(
        "INSERT INTO reservation_items (reservation_id, medicine_id, quantity)
         VALUES (?, ?, 1)"
    );
    $stmt->bind_param('ii', $reservationId, $medicineId);
    $stmt->execute();

    // Notify every active, approved pharmacist assigned to the exact pharmacy
    // selected on the medicine card. This keeps restricted requests consistent
    // with normal reservations and online orders.
    notify_pharmacy_staff(
        $conn,
        $pharmacyId,
        'PRESCRIPTION_UPLOADED',
        'New Prescription Request',
        'A patient has uploaded a prescription for a restricted medicine. Please review it from the Pending tab.',
        null,
        $reservationId,
        $prescriptionId
    );

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    @unlink($destination);
    $_SESSION['upload_error'] = 'Could not create the prescription request. Please try again.';
    $_SESSION['upload_return_url'] = $returnUrl;
    header('Location: ' . $returnUrl); exit;
}

$_SESSION['upload_popup'] = true;
$_SESSION['upload_return_url'] = 'requests.php';
header('Location: prescription_success.php');
exit;
?>