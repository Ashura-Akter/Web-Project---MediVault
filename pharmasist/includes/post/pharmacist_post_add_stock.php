<?php
/**
 * MediVault - Pharmacist module
 * File    : includes/post/pharmacist_post_add_stock.php
 * Title   : POST handler: add stock (new inventory batch)
 *
 * BACKEND (PHP + SQL)
 * Form field: add_stock_submit (modal views/modals/pharmacist_modal_add_stock.php).
 * Inserts one row into inventory_batches for the pharmacist's pharmacy.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');

// 1. ADD QUANTITY / STOCK UPDATE
// Form: the 'Add Stock' modal posts medicine_id, add_quantity, batch_expiry_date and stock_price.
if (isset($_POST['add_stock_submit'])) {
    // Read and sanitise the inputs (int / float casts) before using them.
    $med_id = (int)($_POST['medicine_id'] ?? 0);
    $add_qty = (int)($_POST['add_quantity'] ?? 0);
    $expiry_date = $_POST['batch_expiry_date'] ?? '';
    $stock_price = (float)($_POST['stock_price'] ?? 0);

    // The expiry date must be a valid date in the future, otherwise the batch would never be usable.
    $expiry_ok = preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry_date) === 1 && $expiry_date > date('Y-m-d');

    // Insert only when every value is valid.
    if ($med_id > 0 && $add_qty > 0 && $expiry_ok && $stock_price >= 0) {
        // Each stock addition is saved as its own batch, so a new expiry date creates a new entry.
        // Unique batch number: BATCH-<medicine id>-<unix time>-<4 random hex chars>.
        $batch_no = 'BATCH-' . $med_id . '-' . time() . '-' . bin2hex(random_bytes(2));
        // SQL: INSERT a new row into inventory_batches (one row = one batch with its own expiry date and price).
        // last_restocked_at is set to NOW().
        $batch_stmt = $conn->prepare(
            "INSERT INTO inventory_batches
             (pharmacy_id, medicine_id, batch_no, quantity_on_hand, expiry_date, unit_price, last_restocked_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        );
        // Types: i = int, s = string, d = double  ->  pharmacy, medicine, batch_no, qty, expiry, price.
        $batch_stmt->bind_param('iisisd', $pharmacyId, $med_id, $batch_no, $add_qty, $expiry_date, $stock_price);
        $batch_stmt->execute();
    }

    // Go back to the same tab with ?stock_added=1 (shows the green success banner).
    $tab = $_GET['tab'] ?? 'all';
    header("Location: medivaultpharmasist.php?tab=" . urlencode($tab) . "&stock_added=1");
    exit();
}
