<?php
session_start(); require_once __DIR__ . '/../../database/queries.php';
if(isset($_SESSION['user_id'])) remove_cart_item($conn,(int)$_SESSION['user_id'],(int)($_POST['cart_item_id']??0));
header('Location: ../cart.php'); exit;
?>