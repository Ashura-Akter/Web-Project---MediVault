<?php
/**
 * MediVault - Pharmacist module
 * File    : views/tabs/pharmacist_tab_router.php
 * Title   : Tab router
 *
 * FRONTEND (PHP)
 * Replaces the long if / elseif chain of the original file. The value of ?tab= selects ONE view file.
 * Only names listed in the whitelist below can be loaded (no path injection).
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');

// tab name (?tab=)  =>  view file in views/tabs/
$pharmacist_tab_views = [
    'all'           => 'pharmacist_tab_dashboard.php',            // Dashboard (all medicines)
    'in_stock'      => 'pharmacist_tab_in_stock.php',
    'low_stock'     => 'pharmacist_tab_low_stock.php',
    'out_of_stock'  => 'pharmacist_tab_out_of_stock.php',
    'expiring_soon' => 'pharmacist_tab_expiring_soon.php',
    'pending'       => 'pharmacist_tab_pending.php',              // restricted-medicine requests
    'approved'      => 'pharmacist_tab_approved.php',
    'rejected'      => 'pharmacist_tab_rejected.php',
    'reservation'   => 'pharmacist_tab_reservations.php',
    'res_history'   => 'pharmacist_tab_reservation_history.php',
    'order_history' => 'pharmacist_tab_order_history.php',
];

// An unknown tab name renders nothing (same behaviour as the original if / elseif chain).
if (is_string($tab) && isset($pharmacist_tab_views[$tab])) {
    require PHARMACIST_ROOT . '/views/tabs/' . $pharmacist_tab_views[$tab];
}
