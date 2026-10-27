<?php
session_start();
require_once __DIR__ . '/../database/queries.php';

header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Not authorized.']);
    exit;
}
$userId = (int)$_SESSION['user_id'];
$nid = (int)($_GET['id'] ?? 0);
if ($nid <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid ID.']);
    exit;
}

$reply = get_support_reply_by_notification($conn, $nid, $userId);
if (!$reply) {
    echo json_encode(['ok' => false, 'error' => 'Reply not found.']);
    exit;
}

echo json_encode([
    'ok' => true,
    'admin_name' => (string)($reply['admin_name'] ?? 'Admin'),
    'created_at_formatted' => date('d M Y, h:i A', strtotime((string)$reply['created_at'])),
    'reply_message' => (string)$reply['reply_message'],
    'original_message' => (string)($reply['original_message'] ?? ''),
]);
exit;
