<?php
session_start();
require_once __DIR__ . '/../database/queries.php';

/* AJAX: mark one notification as read (used by support-reply popup). */
if (isset($_GET['ajax_mark_read'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['ok' => false]);
        exit;
    }
    $nid = (int)($_GET['id'] ?? 0);
    if ($nid > 0) {
        $st = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE notification_id = ? AND user_id = ?");
        $uid = (int)$_SESSION['user_id'];
        $st->bind_param('ii', $nid, $uid);
        $st->execute();
    }
    echo json_encode(['ok' => true]);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login/login.php');
    exit;
}
$userId = (int)$_SESSION['user_id'];
$role = strtoupper((string)($_SESSION['role'] ?? 'PATIENT'));
if (!in_array($role, ['PATIENT', 'PHARMACIST', 'ADMIN'], true)) {
    header('Location: ../login/login.php');
    exit;
}

$notificationId = (int)($_GET['id'] ?? 0);
$markAll = isset($_GET['all']) && $_GET['all'] === '1';
$redirect = $role === 'PHARMACIST' ? '../pharmasist/medivaultpharmasist.php?tab=all' : ($role === 'ADMIN' ? '../admin/index.php?tab=all-medicine' : '../patient/requests.php');

if ($markAll) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
} elseif ($notificationId > 0) {
    $stmt = $conn->prepare("SELECT notification_type, title, message, related_order_id, related_reservation_id, related_prescription_id FROM notifications WHERE notification_id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param('ii', $notificationId, $userId);
    $stmt->execute();
    $n = $stmt->get_result()->fetch_assoc();
    if ($n) {
        $up = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE notification_id = ? AND user_id = ?");
        $up->bind_param('ii', $notificationId, $userId);
        $up->execute();

        $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
        $samePage = ($ref !== '' && strpos($ref, '/common/notification.php') === false);

        /* Support Reply → bounce back to previous page (popup handled by topbar.js) */
        if (($n['title'] ?? '') === 'Support Reply') {
            $redirect = $samePage ? $ref : ($role === 'PATIENT' ? '../patient/browse.php' : ($role === 'PHARMACIST' ? '../pharmasist/medivaultpharmasist.php?tab=all' : '../admin/index.php?tab=all-medicine'));
        } elseif ($role === 'PATIENT') {
            if (!empty($n['related_order_id'])) $redirect = '../patient/order_details.php?id=' . (int)$n['related_order_id'];
            elseif ($n['notification_type'] === 'PRESCRIPTION_APPROVED') {
                $cartCount = get_cart_item_count($conn, $userId);
                $redirect = $cartCount > 0 ? '../patient/checkout.php' : '../patient/cart.php';
            } elseif (!empty($n['related_reservation_id']) || !empty($n['related_prescription_id'])) $redirect = '../patient/requests.php';
        } elseif ($role === 'ADMIN') {
            $t = (string)($n['title'] ?? '');
            if ($n['notification_type'] === 'PHARMACIST_APPROVAL' || $t === 'New Pharmacist Registration') $redirect = '../admin/index.php?tab=pending-approvals';
            elseif ($t === 'Help & Support Request') $redirect = '../admin/index.php?tab=support';
            elseif (in_array($n['notification_type'], ['LOW_STOCK', 'EXPIRY_WARNING'], true)) $redirect = '../admin/index.php?tab=stock-expiry';
            elseif ($t === 'New Patient Registration') $redirect = '../admin/index.php?tab=all-users&role=PATIENT';
        } elseif ($role === 'PHARMACIST') {
            if ($n['notification_type'] === 'LOW_STOCK') $redirect = '../pharmasist/medivaultpharmasist.php?tab=low_stock';
            elseif ($n['notification_type'] === 'EXPIRY_WARNING') $redirect = '../pharmasist/medivaultpharmasist.php?tab=expiring_soon';
            elseif ($n['notification_type'] === 'ORDER_UPDATE') $redirect = '../pharmasist/medivaultpharmasist.php?tab=order_history&order_received=1';
            elseif ($n['notification_type'] === 'PRESCRIPTION_UPLOADED') $redirect = '../pharmasist/medivaultpharmasist.php?tab=pending';
            elseif (str_starts_with($n['notification_type'], 'RESERVATION_')) $redirect = '../pharmasist/medivaultpharmasist.php?tab=reservation';
        }
    }
}
header('Location: ' . $redirect);
exit;
