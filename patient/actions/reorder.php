<?php
session_start();
require_once __DIR__ . '/../../database/queries.php';
if (!isset($_SESSION['user_id'])) { header('Location: ../../login/login.php'); exit; }
$userId=(int)$_SESSION['user_id'];
$orderId=(int)($_POST['order_id'] ?? 0);
if($orderId<=0){ header('Location: ../orders.php'); exit; }

$order=get_order_details($conn,$userId,$orderId);
if(!$order){ header('Location: ../orders.php'); exit; }

$added=0;
foreach($order['items'] as $item){
    $medicineId=(int)$item['medicine_id'];
    $quantity=max(1,(int)$item['quantity']);
    $pharmacyId=(int)$order['pharmacy_id'];
    if($medicineId<=0 || $pharmacyId<=0) continue;
    if(add_to_cart($conn,$userId,$medicineId,$quantity,$pharmacyId)) $added++;
}
header('Location: ../cart.php');
exit;
