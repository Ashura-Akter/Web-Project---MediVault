<?php
session_start();

require_once __DIR__ . '/../../database/queries.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login/login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$medicineId = (int)($_POST['medicine_id'] ?? 0);
$pharmacyId = (int)($_POST['pharmacy_id'] ?? 0);

if ($medicineId <= 0 || $pharmacyId <= 0) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['success' => false, 'saved' => false]);
        exit;
    }
    header('Location: ../browse.php?view=all');
    exit;
}

toggle_favorite($conn, $userId, $medicineId, $pharmacyId);

// Return the new state for the inline heart button.
$stmt = $conn->prepare("SELECT 1 FROM favorite_medicines WHERE user_id = ? AND medicine_id = ? AND pharmacy_id = ? LIMIT 1");
$stmt->bind_param('iii', $userId, $medicineId, $pharmacyId);
$stmt->execute();
$saved = (bool)$stmt->get_result()->fetch_row();

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
          (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'saved' => $saved]);
    exit;
}

// Fallback for normal non-JavaScript form submissions.
header('Location: ../shelf.php');
exit;
?>