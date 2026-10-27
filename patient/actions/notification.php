<?php
session_start();
require_once __DIR__ . '/../../database/queries.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login/login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$notificationId = (int)($_GET['id'] ?? 0);
$markAll = isset($_GET['all']) && $_GET['all'] === '1';

if ($markAll) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
} elseif ($notificationId > 0) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE notification_id = ? AND user_id = ?");
    $stmt->bind_param('ii', $notificationId, $userId);
    $stmt->execute();
}

header('Location: ../requests.php');
exit;
