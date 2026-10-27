<?php
/**
 * MediVault - Pharmacist module
 * File    : includes/pharmacist_ajax_counts.php
 * Title   : AJAX endpoint: live counters (?counts=1)
 *
 * BACKEND (PHP + SQL) - returns JSON
 * Used by assets/js/pharmacist_live_counts.js to refresh the Reserved Request card, the New Orders card
 * and the notification bell badge without reloading the page.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');

// Live counts for the Reserved Request card and the bell badge (AJAX, returns JSON)
// Endpoint: medivaultpharmasist.php?counts=1  (polled every 20 seconds by pharmacist_live_counts.js).
if (isset($_GET['counts'])) {
    // This endpoint answers with JSON, not with an HTML page.
    header('Content-Type: application/json; charset=utf-8');
    // reserved = open requests of the Reservations tab, rx = open prescription requests of the Pending tab.
    $c_reserved = nb_count_requests($conn, $pharmacyId, $res_tab_filters['reservation']);
    $c_rx = nb_count_requests($conn, $pharmacyId, $res_tab_filters['pending']);
    // SQL: number of unread notifications of this pharmacist (notifications.is_read = 0).
    $st = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $st->bind_param('i', $pharmacistId);
    $st->execute();
    $c_unread = (int)$st->get_result()->fetch_column();
    // SQL: unread notifications of type ORDER_UPDATE = value of the 'New Orders' card.
    $no = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND notification_type='ORDER_UPDATE'");
    $no->bind_param('i', $pharmacistId);
    $no->execute();
    $c_new_orders = (int)$no->get_result()->fetch_column();
    // badge = total number shown on the bell icon. Send the JSON and stop (no HTML is rendered).
    echo json_encode(['reserved' => $c_reserved, 'new_orders' => $c_new_orders, 'badge' => $c_reserved + $c_rx + $c_unread]);
    exit;
}
