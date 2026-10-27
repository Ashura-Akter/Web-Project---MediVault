<?php
/**
 * MediVault - Pharmacist module
 * File    : includes/pharmacist_notification_data.php
 * Title   : Notification bell data
 *
 * BACKEND (PHP + SQL)
 * Provides: $notif_unread, $notif_stored, $notif_requests, $nb_badge, $nb_names.
 * These variables are used by ../common/topbar.php (the bell and the profile menu).
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');

// Notification bell data
// 1) Unread notifications stored for this pharmacist.
// SQL: count of unread notifications of this pharmacist.
$nq = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$nq->bind_param('i', $pharmacistId);
$nq->execute();
$notif_unread = (int)$nq->get_result()->fetch_column();

// SQL: the 5 latest notifications (unread first, newest first); TIMESTAMPDIFF gives the age in minutes.
$nq = $conn->prepare(
    "SELECT notification_id, notification_type, title, message, is_read,
            TIMESTAMPDIFF(MINUTE, created_at, NOW()) AS mins_ago
     FROM notifications
     WHERE user_id = ?
     ORDER BY is_read ASC, created_at DESC, notification_id DESC
     LIMIT 5"
);
$nq->bind_param('i', $pharmacistId);
$nq->execute();
$notif_stored = $nq->get_result()->fetch_all(MYSQLI_ASSOC);

// 2) Patient requests waiting for this pharmacy (restricted medicine requests and reservations).
// SQL: reservations of this pharmacy that are still PENDING / AWAITING_RX.
// GROUP_CONCAT joins the medicine names into one text; MAX(requires_prescription) flags restricted medicines;
// TIMESTAMPDIFF gives the minutes since the request was made. LIMIT 6.
$nq = $conn->prepare(
    "SELECT r.reservation_id, r.reservation_number, r.prescription_id,
            u.full_name AS patient_name,
            MAX(m.requires_prescription) AS needs_rx,
            GROUP_CONCAT(m.medicine_name ORDER BY m.medicine_name SEPARATOR ', ') AS medicines,
            TIMESTAMPDIFF(MINUTE, r.requested_at, NOW()) AS mins_ago
     FROM reservations r
     JOIN users u ON u.user_id = r.patient_id
     JOIN reservation_items ri ON ri.reservation_id = r.reservation_id
     JOIN medicines m ON m.medicine_id = ri.medicine_id
     WHERE r.pharmacy_id = ? AND r.status IN ('PENDING','AWAITING_RX')
     GROUP BY r.reservation_id, r.reservation_number, r.prescription_id, u.full_name, r.requested_at
     ORDER BY r.requested_at DESC
     LIMIT 6"
);
$nq->bind_param('i', $pharmacyId);
$nq->execute();
$notif_requests = $nq->get_result()->fetch_all(MYSQLI_ASSOC);

// Bell badge = open reservations + open prescription requests + unread notifications.
$nb_badge = $reserved_req + $pending_rx_req + $notif_unread;
// Names of the logged-in user (the top bar uses them to recognise the current user).
$nb_names = array_values(array_unique(array_filter([
    trim((string)$pharmacist['full_name']),
    trim((string)($_SESSION['full_name'] ?? ''))
])));
