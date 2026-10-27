<?php
/**
 * MediVault - Pharmacist module
 * File    : includes/pharmacist_dashboard_stats.php
 * Title   : Dashboard KPI numbers
 *
 * BACKEND (PHP + SQL)
 * Provides: $total_meds, $low_stock, $out_of_stock, $expiring, $reserved_req, $pending_rx_req.
 * All counts are limited to the pharmacist's pharmacy.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');

// Dashboard KPI Stats, scoped to this pharmacist's pharmacy.
$total_meds = 0;
// SQL (Total Medicine): number of different ACTIVE medicines that have at least one batch in this pharmacy.
$stmt = $conn->prepare(
    "SELECT COUNT(DISTINCT m.medicine_id) AS total
     FROM medicines m
     JOIN inventory_batches ib ON ib.medicine_id = m.medicine_id
     WHERE m.is_active = TRUE AND ib.pharmacy_id = ?"
);
$stmt->bind_param('i', $pharmacyId);
$stmt->execute();
$total_meds = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);

$low_stock = 0;
// SQL (Low stock alert): the derived table groups every active medicine and keeps those whose free stock
// (quantity_on_hand - reserved_quantity) is <= reorder_level. LEFT JOIN keeps medicines without any batch.
// The outer query only COUNTs the rows.
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM (
         SELECT m.medicine_id
         FROM medicines m
         LEFT JOIN inventory_batches ib
           ON ib.medicine_id = m.medicine_id AND ib.pharmacy_id = ?
         WHERE m.is_active = TRUE
         GROUP BY m.medicine_id, m.reorder_level
         HAVING COALESCE(SUM(ib.quantity_on_hand - ib.reserved_quantity), 0) <= m.reorder_level
     ) AS low_stock_tb"
);
$stmt->bind_param('i', $pharmacyId);
$stmt->execute();
$low_stock = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);

$out_of_stock = 0;
// SQL (Out of stock): active medicines whose NOT expired free quantity sums to 0 (expired batches do not count).
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM (
         SELECT m.medicine_id
         FROM medicines m
         LEFT JOIN inventory_batches ib
           ON ib.medicine_id = m.medicine_id AND ib.pharmacy_id = ?
         WHERE m.is_active = TRUE
         GROUP BY m.medicine_id
         HAVING COALESCE(SUM(CASE WHEN ib.expiry_date > CURDATE() THEN GREATEST(ib.quantity_on_hand - ib.reserved_quantity, 0) ELSE 0 END), 0) = 0
     ) AS out_stock_tb"
);
$stmt->bind_param('i', $pharmacyId);
$stmt->execute();
$out_of_stock = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);

$expiring = 0;
// SQL (Expiring in 90 days): distinct medicines having a batch that expires between today and today + 90 days.
$stmt = $conn->prepare(
    "SELECT COUNT(DISTINCT ib.medicine_id) AS total
     FROM inventory_batches ib
     JOIN medicines m ON m.medicine_id = ib.medicine_id
     WHERE ib.pharmacy_id = ?
       AND m.is_active = TRUE
       AND ib.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)"
);
$stmt->bind_param('i', $pharmacyId);
$stmt->execute();
$expiring = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);

// Reserved Request card = open requests shown in the Reservations tab.
$reserved_req = nb_count_requests($conn, $pharmacyId, $res_tab_filters['reservation']);
// Restricted-medicine (prescription) requests waiting in the Pending tab.
$pending_rx_req = nb_count_requests($conn, $pharmacyId, $res_tab_filters['pending']);
