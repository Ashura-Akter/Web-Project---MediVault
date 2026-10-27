<?php
/**
 * MediVault - Pharmacist module
 * File    : includes/pharmacist_ajax_suggest.php
 * Title   : AJAX endpoint: live search suggestions (?suggest=1)
 *
 * BACKEND (PHP + SQL) - returns JSON
 * Used by assets/js/pharmacist_live_search.js. A different SQL query is used for every tab
 * (dashboard, low stock, in stock, out of stock, expiring soon and the reservation-based tabs).
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');

// Live search suggestions (AJAX, returns JSON)
// Endpoint: ?suggest=1&tab=<tab>&q=<text>  (called by pharmacist_live_search.js while the pharmacist types).
if (isset($_GET['suggest'])) {
    header('Content-Type: application/json; charset=utf-8');
    // Which tab is searching and what was typed.
    $sg_tab = (string)($_GET['tab'] ?? 'all');
    $sg_q = trim((string)($_GET['q'] ?? ''));
    $sg_rows = [];
    if ($sg_q !== '') {
        // $like = 'contains' match, $prefix = 'starts with' match (used to rank prefix matches first).
        $like = '%' . $sg_q . '%';
        $prefix = $sg_q . '%';
        if ($sg_tab === 'all') {
            // SQL (Dashboard tab): distinct medicine names that match name, generic name, category or company.
            // ORDER BY (name LIKE prefix) DESC puts names that START with the typed text first. LIMIT 8.
            $st = $conn->prepare(
                "SELECT DISTINCT m.medicine_name AS label
                 FROM medicines m
                 LEFT JOIN categories c ON c.category_id = m.category_id
                 WHERE m.is_active = TRUE
                   AND (m.medicine_name LIKE ? OR m.generic_name LIKE ? OR c.category_name LIKE ? OR m.company_name LIKE ?)
                 ORDER BY (m.medicine_name LIKE ?) DESC, m.medicine_name
                 LIMIT 8"
            );
            $st->bind_param('sssss', $like, $like, $like, $like, $prefix);
        } elseif ($sg_tab === 'low_stock') {
            // SQL (Low Stock tab): medicines whose available stock (quantity_on_hand - reserved_quantity)
            // summed over all batches is <= reorder_level. HAVING filters AFTER the SUM per medicine.
            $st = $conn->prepare(
                "SELECT m.medicine_name AS label
                 FROM medicines m
                 LEFT JOIN inventory_batches ib ON ib.medicine_id = m.medicine_id AND ib.pharmacy_id = ?
                 WHERE m.is_active = TRUE AND m.medicine_name LIKE ?
                 GROUP BY m.medicine_id, m.medicine_name, m.reorder_level
                 HAVING IFNULL(SUM(ib.quantity_on_hand - ib.reserved_quantity), 0) <= m.reorder_level
                 ORDER BY m.medicine_name
                 LIMIT 8"
            );
            $st->bind_param('is', $pharmacyId, $like);
        } elseif ($sg_tab === 'in_stock') {
            // SQL (In Stock tab): medicines that have at least one NOT expired batch with free quantity.
            // GREATEST(...,0) prevents negative stock; expired batches are ignored (expiry_date > CURDATE()).
            $st = $conn->prepare(
                "SELECT m.medicine_name AS label
                 FROM medicines m
                 JOIN inventory_batches ib ON ib.medicine_id = m.medicine_id AND ib.pharmacy_id = ?
                 WHERE m.is_active = TRUE AND m.medicine_name LIKE ?
                 GROUP BY m.medicine_id, m.medicine_name
                 HAVING COALESCE(SUM(CASE WHEN ib.expiry_date > CURDATE() THEN GREATEST(ib.quantity_on_hand - ib.reserved_quantity, 0) ELSE 0 END), 0) > 0
                 ORDER BY m.medicine_name
                 LIMIT 8"
            );
            $st->bind_param('is', $pharmacyId, $like);
        } elseif ($sg_tab === 'out_of_stock') {
            // SQL (Out of Stock tab): medicines whose NOT expired available quantity sums to 0.
            $st = $conn->prepare(
                "SELECT m.medicine_name AS label
                 FROM medicines m
                 LEFT JOIN inventory_batches ib ON ib.medicine_id = m.medicine_id AND ib.pharmacy_id = ?
                 WHERE m.is_active = TRUE AND m.medicine_name LIKE ?
                 GROUP BY m.medicine_id, m.medicine_name
                 HAVING COALESCE(SUM(CASE WHEN ib.expiry_date > CURDATE() THEN GREATEST(ib.quantity_on_hand - ib.reserved_quantity, 0) ELSE 0 END), 0) = 0
                 ORDER BY m.medicine_name
                 LIMIT 8"
            );
            $st->bind_param('is', $pharmacyId, $like);
        } elseif ($sg_tab === 'expiring_soon') {
            // SQL (Expiring Soon tab): medicines with a batch that expires within the next 90 days.
            $st = $conn->prepare(
                "SELECT DISTINCT m.medicine_name AS label
                 FROM medicines m
                 JOIN inventory_batches ib ON ib.medicine_id = m.medicine_id
                 WHERE m.is_active = TRUE
                   AND ib.pharmacy_id = ?
                   AND ib.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
                   AND m.medicine_name LIKE ?
                 ORDER BY m.medicine_name
                 LIMIT 8"
            );
            $st->bind_param('is', $pharmacyId, $like);
        } elseif (isset($res_tab_filters[$sg_tab])) {
            // Reservation-based tabs reuse that tab's WHERE fragment ($res_tab_filters).
            $f = $res_tab_filters[$sg_tab];
            // SQL: UNION of three label sources so the pharmacist can search by
            //   1) medicine name  2) patient name  3) reservation number.
            // UNION removes duplicates; the outer query ranks prefix matches first and keeps 8 rows.
            $st = $conn->prepare(
                "SELECT label FROM (
                     SELECT m.medicine_name AS label
                     FROM reservations r
                     JOIN reservation_items ri ON ri.reservation_id = r.reservation_id
                     JOIN medicines m ON m.medicine_id = ri.medicine_id
                     WHERE r.pharmacy_id = ? AND $f AND m.medicine_name LIKE ?
                     UNION
                     SELECT u.full_name AS label
                     FROM reservations r
                     JOIN users u ON u.user_id = r.patient_id
                     WHERE r.pharmacy_id = ? AND $f AND u.full_name LIKE ?
                     UNION
                     SELECT r.reservation_number AS label
                     FROM reservations r
                     WHERE r.pharmacy_id = ? AND $f AND r.reservation_number LIKE ?
                       AND EXISTS (SELECT 1 FROM reservation_items rx WHERE rx.reservation_id = r.reservation_id)
                 ) s
                 ORDER BY (label LIKE ?) DESC, label
                 LIMIT 8"
            );
            $st->bind_param('isisiss', $pharmacyId, $like, $pharmacyId, $like, $pharmacyId, $like, $prefix);
        }
        // Run the query of the matching branch (if any) and keep only the 'label' column.
        if (isset($st)) {
            $st->execute();
            $sg_rows = array_column($st->get_result()->fetch_all(MYSQLI_ASSOC), 'label');
        }
    }
    // Return the suggestions as a JSON array and stop.
    echo json_encode($sg_rows);
    exit;
}
