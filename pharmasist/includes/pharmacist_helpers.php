<?php
/**
 * MediVault - Pharmacist module
 * File    : includes/pharmacist_helpers.php
 * Title   : Shared helper functions and tab filters
 *
 * BACKEND (PHP + SQL)
 * nb_time_ago(), nb_notif_tab(), nb_count_requests() and the $res_tab_filters array
 * (the WHERE fragments that define which reservations belong to which tab).
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');

// Notification bell helpers
// nb_time_ago(): turns a number of minutes into a short label such as '5 min ago' or '2 hr ago'.
if (!function_exists('nb_time_ago')) {
    function nb_time_ago($m) {
        if ($m < 1) return 'just now';
        if ($m < 60) return $m . ' min ago';
        if ($m < 1440) return floor($m / 60) . ' hr ago';
        if ($m < 10080) return floor($m / 1440) . ' d ago';
        return floor($m / 10080) . ' wk ago';
    }
}
// nb_notif_tab(): maps a notification type to the dashboard tab that opens when the bell item is clicked.
if (!function_exists('nb_notif_tab')) {
    function nb_notif_tab($type) {
        $map = [
            'PRESCRIPTION_UPLOADED' => 'pending',
            'RESERVATION_ACCEPTED'  => 'reservation',
            'RESERVATION_READY'     => 'reservation',
            'RESERVATION_CANCELLED' => 'res_history',
            'ORDER_UPDATE' => 'order_history',
            'PAYMENT_UPDATE' => 'order_history',
            'LOW_STOCK'             => 'low_stock',
            'EXPIRY_WARNING'        => 'expiring_soon',
        ];
        return $map[$type] ?? null;
    }
}

// Counts requests exactly the way the tab lists show them (same joins and filters),
// so a number on a card never differs from what you see after clicking it.
if (!function_exists('nb_count_requests')) {
    function nb_count_requests($conn, $pharmacyId, $filter) {
        // SQL: COUNT(DISTINCT reservation) using exactly the same joins as the tab lists.
        //   reservations r -> users u (patient) -> reservation_items ri -> medicines m
        //   r.pharmacy_id = ? limits it to this pharmacy; $filter is one WHERE fragment from $res_tab_filters.
        // $filter comes only from the hard-coded array below, never from user input (no SQL injection).
        $st = $conn->prepare(
            "SELECT COUNT(DISTINCT r.reservation_id)
             FROM reservations r
             JOIN users u ON u.user_id = r.patient_id
             JOIN reservation_items ri ON ri.reservation_id = r.reservation_id
             JOIN medicines m ON m.medicine_id = ri.medicine_id
             WHERE r.pharmacy_id = ? AND $filter"
        );
        $st->bind_param('i', $pharmacyId);
        $st->execute();
        return (int)$st->get_result()->fetch_column();
    }
}

// Row filters used by the reservation-based tabs (list queries + live suggestions).
// 'approved' and 'rejected' are separate tabs: rejected requests no longer show under Approved.
$res_tab_filters = [
    // Pending tab: restricted-medicine requests (a prescription was uploaded) waiting for a decision.
    'pending'     => "r.prescription_id IS NOT NULL AND r.status IN ('PENDING','AWAITING_RX')",
    // Approved tab: approved / ready / accepted / collected requests.
    'approved'    => "r.status IN ('APPROVED','READY','ACCEPTED','COLLECTED')",
    // Rejected tab: requests the pharmacist rejected.
    'rejected'    => "r.status = 'REJECTED'",
    // Reservations tab: normal reservations (no prescription) waiting for the pharmacist.
    'reservation' => "r.prescription_id IS NULL AND r.status IN ('PENDING','AWAITING_RX')",
    // Reservation History tab: normal reservations that are finished or closed.
    'res_history' => "r.prescription_id IS NULL AND r.status IN ('ACCEPTED','READY','COLLECTED','NOT_COLLECTED','CANCELLED','REJECTED','EXPIRED')",
];
