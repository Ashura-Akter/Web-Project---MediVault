<?php
session_start();

require_once __DIR__ . '/../../database/queries.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login/login.php');
    exit;
}

$medicineId = (int)($_POST['medicine_id'] ?? 0);
$quantity = max(1, (int)($_POST['quantity'] ?? 1));
$pharmacyId = (int)($_POST['pharmacy_id'] ?? 0);

$ok=add_to_cart($conn,(int)$_SESSION['user_id'],$medicineId,$quantity,$pharmacyId);
if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'])==='xmlhttprequest') { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>$ok,'count'=>get_cart_item_count($conn,(int)$_SESSION['user_id'])]); exit; }
header('Location: ../cart.php'); exit;
?>