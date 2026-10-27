<?php
/**
 * MediVault - Pharmacist module
 * File    : includes/post/pharmacist_post_notifications.php
 * Title   : POST handler: notification bell (mark as read)
 *
 * BACKEND (PHP + SQL)
 * Form field: notif_action = read_all | read_one. Updates notifications.is_read / read_at.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');

// 0. NOTIFICATION BELL: mark as read
// Form fields: notif_action = read_all | read_one, go = tab to open afterwards.
if (isset($_POST['notif_action'])) {
    $go = (string)($_POST['go'] ?? 'all');
    // Whitelist of tabs allowed as redirect target (a tampered value falls back to 'all').
    if (!in_array($go, ['all', 'low_stock', 'expiring_soon', 'pending', 'approved', 'rejected', 'reservation', 'res_history', 'order_history'], true)) {
        $go = 'all';
    }
    if ($_POST['notif_action'] === 'read_all') {
        // SQL: mark ALL unread notifications of this pharmacist as read and store read_at = now.
        $st = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0");
        $st->bind_param('i', $pharmacistId);
        $st->execute();
    } elseif ($_POST['notif_action'] === 'read_one') {
        $nid = (int)($_POST['notification_id'] ?? 0);
        // SQL: mark ONE notification as read. user_id = ? makes sure nobody can touch another user's notification.
        $st = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE notification_id = ? AND user_id = ? AND is_read = 0");
        $st->bind_param('ii', $nid, $pharmacistId);
        $st->execute();
    }
    // POST/Redirect/GET: redirect so refreshing the page does not submit the form again.
    header('Location: medivaultpharmasist.php?tab=' . urlencode($go));
    exit();
}
