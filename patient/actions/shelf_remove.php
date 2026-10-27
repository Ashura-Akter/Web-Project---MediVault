<?php
session_start();
require_once __DIR__ . '/../../database/queries.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login/login.php');
    exit;
}

$favoriteId = (int)($_POST['favorite_id'] ?? 0);
$medicineId = (int)($_POST['medicine_id'] ?? 0);
$pharmacyId = (int)($_POST['pharmacy_id'] ?? 0);
$userId = (int)$_SESSION['user_id'];

if ($favoriteId > 0) {
    $stmt = $conn->prepare("DELETE FROM favorite_medicines WHERE favorite_id = ? AND user_id = ?");
    $stmt->bind_param('ii', $favoriteId, $userId);
    $stmt->execute();
} elseif ($medicineId > 0 && $pharmacyId > 0) {
    $stmt = $conn->prepare("DELETE FROM favorite_medicines WHERE user_id = ? AND medicine_id = ? AND pharmacy_id = ?");
    $stmt->bind_param('iii', $userId, $medicineId, $pharmacyId);
    $stmt->execute();
}

header('Location: ../shelf.php');
exit;
