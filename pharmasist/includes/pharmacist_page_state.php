<?php
/**
 * MediVault - Pharmacist module
 * File    : includes/pharmacist_page_state.php
 * Title   : Page state: active tab, search, popups, notification read-marking
 *
 * BACKEND (PHP + SQL)
 * Provides: $tab, $search, popup flags, $new_order_count and the reservation search fragment
 * ($res_search_sql, $res_search_types, $res_search_params) used by the reservation-based tabs.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');

// Active Tab & Modal States
// $tab = active tab (default 'all' = Dashboard), $search = text of the search box.
$tab = $_GET['tab'] ?? 'all';
$search = trim($_GET['search'] ?? '');

// Opening Order History marks the 'new order' notifications as read.
if ($tab === 'order_history') {
    // SQL: UPDATE notifications SET is_read = 1 for this pharmacist's ORDER_UPDATE notifications.
    $readOrderNotices = $conn->prepare("UPDATE notifications SET is_read=1, read_at=NOW() WHERE user_id=? AND is_read=0 AND notification_type='ORDER_UPDATE'");
    $readOrderNotices->bind_param('i', $pharmacistId);
    $readOrderNotices->execute();
}
// Opening a page from the left menu counts as reading the notifications that belong to it.
// Map: tab name => notification types that count as 'read' when that tab is opened.
$tabNotifTypes = [
    'low_stock'     => ['LOW_STOCK'],
    'expiring_soon' => ['EXPIRY_WARNING'],
    'pending'       => ['PRESCRIPTION_UPLOADED'],
    'reservation'   => ['RESERVATION_ACCEPTED', 'RESERVATION_READY'],
    'res_history'   => ['RESERVATION_CANCELLED'],
];
// mark_notifications_read() is a helper from database/queries.php.
if (isset($tabNotifTypes[$tab])) {
    mark_notifications_read($conn, $pharmacistId, $tabNotifTypes[$tab]);
}
// Popup flags that arrive in the URL after a redirect (?reservation_accepted, ?order_received, ?show_cancel_modal).
$show_accepted_popup = isset($_GET['reservation_accepted']) && $_GET['reservation_accepted'] == '1';
$approval_type = (string)($_GET['approval_type'] ?? 'reservation');
$show_order_received_popup = isset($_GET['order_received']) && $_GET['order_received'] == '1';
$show_cancelled_popup = isset($_GET['show_cancel_modal']) && $_GET['show_cancel_modal'] == '1';
$cancelled_res_id = (int)($_GET['res_id'] ?? 0);

// SQL: unread ORDER_UPDATE notifications = value of the 'New Orders' card.
$new_order_count_stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND notification_type='ORDER_UPDATE'");
$new_order_count_stmt->bind_param('i', $pharmacistId);
$new_order_count_stmt->execute();
$new_order_count = (int)$new_order_count_stmt->get_result()->fetch_column();

// Search filter for the reservation-based tabs (patient name, reservation no. or medicine name).
$res_search_sql = '';
$res_search_types = '';
$res_search_params = [];
// Only reservation-based tabs get the extra search condition (3 bound text values).
if ($search !== '' && isset($res_tab_filters[$tab])) {
    // SQL fragment: match patient name, reservation number OR (EXISTS sub-query) any medicine of the reservation.
    // The same fragment is appended to every reservation-based tab query ({$res_search_sql}).
    $res_search_sql = " AND (u.full_name LIKE ? OR r.reservation_number LIKE ? OR EXISTS (
        SELECT 1 FROM reservation_items rsi
        JOIN medicines rsm ON rsm.medicine_id = rsi.medicine_id
        WHERE rsi.reservation_id = r.reservation_id AND rsm.medicine_name LIKE ?))";
    $sl = '%' . $search . '%';
    $res_search_types = 'sss';
    $res_search_params = [$sl, $sl, $sl];
}
