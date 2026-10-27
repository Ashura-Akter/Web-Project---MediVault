<?php
/**
 * MediVault - Pharmacist module
 * File    : includes/pharmacist_medicine_query.php
 * Title   : Medicine list query for the Dashboard tab
 *
 * BACKEND (PHP + SQL)
 * Builds and runs the main SQL query. Provides $medicines_result for views/tabs/pharmacist_tab_dashboard.php.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');

// Dynamic Query for Medicines Tab.
// SQL (Dashboard tab): one row per medicine AND expiry date.
//   medicines m           -> base table (only ACTIVE medicines)
//   categories c          -> LEFT JOIN, gives category_name
//   inventory_batches ib  -> LEFT JOIN, only this pharmacy, NOT expired and quantity > 0
//   derived table mt      -> total quantity of the medicine in this pharmacy (used for the Low stock badge)
//   COALESCE(SUM(...),0)  -> 0 instead of NULL when there is no batch
$med_query = "SELECT m.*, c.category_name,
              COALESCE(SUM(ib.quantity_on_hand), 0) AS total_qty,
              MAX(mt.med_total) AS med_total_qty,
              ib.expiry_date AS min_expiry,
              ib.unit_price AS stock_price
              FROM medicines m
              LEFT JOIN categories c ON m.category_id = c.category_id
              LEFT JOIN inventory_batches ib
                ON ib.medicine_id = m.medicine_id
               AND ib.pharmacy_id = ?
               AND ib.expiry_date > CURRENT_DATE()
               AND ib.quantity_on_hand > 0
              LEFT JOIN (
                    SELECT medicine_id, SUM(quantity_on_hand) AS med_total
                    FROM inventory_batches
                    WHERE pharmacy_id = ? AND expiry_date > CURRENT_DATE()
                    GROUP BY medicine_id
              ) mt ON mt.medicine_id = m.medicine_id
              WHERE m.is_active = TRUE";

// Bound values for the two '?' marks: the pharmacy id is used in the batch join and in the sub-query.
$med_params = [$pharmacyId, $pharmacyId];
$med_types = 'ii';

// Dashboard search: matches name, description, company or category.
if ($tab === 'all' && $search !== '') {
    $med_query .= " AND (m.medicine_name LIKE ? OR m.description LIKE ? OR m.company_name LIKE ? OR c.category_name LIKE ?)";
    $search_param = '%' . $search . '%';
    $med_types .= 'ssss';
    array_push($med_params, $search_param, $search_param, $search_param, $search_param);
}

// One card per medicine and expiry date: a new expiry date creates a new card for the same medicine.
$med_query .= " GROUP BY m.medicine_id, ib.expiry_date, ib.unit_price ORDER BY m.medicine_id DESC, ib.expiry_date ASC";
// Run the query. $medicines_result is read by views/tabs/pharmacist_tab_dashboard.php.
$stmt = $conn->prepare($med_query);
$stmt->bind_param($med_types, ...$med_params);
$stmt->execute();
$medicines_result = $stmt->get_result();
