<?php
/**
 * MediVault - Pharmacist module
 * File    : views/modals/pharmacist_modal_add_stock.php
 * Title   : Modal: Add Stock
 *
 * FRONTEND (HTML)
 * Overlay with quantity, expiry date and price. Opened by openStockModal() (assets/js/pharmacist_dashboard_core.js).
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');
?>
<!-- ADD STOCK OVERLAY MODAL -->
<div class="modal-overlay" id="addStockModal" style="display: none;">
    <div class="modal-card">
        <div class="modal-title">
            <span style="color: #00897b;">📦</span> Add Stock
        </div>
        <div class="modal-description" id="stockModalMedName">
            Adding Stock
        </div>
        <!-- Posts to this same page. Handled by includes/post/pharmacist_post_add_stock.php (button name = add_stock_submit). -->
        <form method="POST">
            <!-- Filled by openStockModal() in assets/js/pharmacist_dashboard_core.js. -->
            <input type="hidden" name="medicine_id" id="modalMedicineId">
            
            <div class="modal-input-group">
                <label for="add_quantity">Quantity to Add</label>
                <input type="number" min="1" name="add_quantity" id="add_quantity" class="modal-input" placeholder="e.g. 50" required>
            </div>

            <div class="modal-input-group">
                <label for="batch_expiry_date">Batch Expiry Date</label>
                <!-- min = tomorrow: expired batches cannot be added (the server checks this again). -->
                <input type="date" name="batch_expiry_date" id="batch_expiry_date" class="modal-input" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
            </div>

            <div class="modal-input-group">
                <label for="stock_price">Price (tk.)</label>
                <input type="number" min="0" step="0.01" name="stock_price" id="stock_price" class="modal-input" placeholder="e.g. 50" required>
            </div>

            <div style="margin-top: 28px; display: flex; justify-content: center;">
                <!-- Cancel only hides the modal (closeStockModal()). -->
                <button type="button" class="btn-modal-cancel" onclick="closeStockModal()">Cancel</button>
                <button type="submit" name="add_stock_submit" class="btn-modal-action">Update Stock</button>
            </div>
        </form>
    </div>
</div>
