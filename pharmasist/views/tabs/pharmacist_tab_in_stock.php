<?php
/**
 * MediVault - Pharmacist module
 * File    : views/tabs/pharmacist_tab_in_stock.php
 * Title   : Tab: In Stock (?tab=in_stock)
 *
 * FRONTEND (HTML) + BACKEND (PHP + SQL)
 * Medicines that can be sold right now.
 * SQL inside this file.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');
?>
        <!-- List of medicines that are available right now. -->
        <div class="single-alert-container">
            <div class="alert-heading" style="color: #078f86;">
                ✔ In Stock Items
            </div>
            <?php
            // SQL: available_qty = free quantity of NOT expired batches; nearest_expiry = earliest expiry date of a batch
            // that still has free stock. INNER JOIN: only medicines that have batches in this pharmacy.
            // HAVING available_qty > 0 keeps only medicines that can be sold.
            $in_q = "SELECT m.medicine_id, m.medicine_name,
                            COALESCE(SUM(CASE WHEN ib.expiry_date > CURDATE() THEN GREATEST(ib.quantity_on_hand - ib.reserved_quantity, 0) ELSE 0 END), 0) AS available_qty,
                            MIN(CASE WHEN ib.expiry_date > CURDATE() AND (ib.quantity_on_hand - ib.reserved_quantity) > 0 THEN ib.expiry_date END) AS nearest_expiry
                     FROM medicines m
                     JOIN inventory_batches ib
                       ON m.medicine_id = ib.medicine_id AND ib.pharmacy_id = ?
                     WHERE m.is_active = TRUE";
            // Bound parameters: [pharmacy id] (+ search text when searching).
            $in_params = [$pharmacyId];
            $in_types = 'i';

            // Append the search condition.
            if ($search !== '') {
                $in_q .= " AND m.medicine_name LIKE ?";
                $in_types .= 's';
                $in_params[] = "%" . $search . "%";
            }
            $in_q .= " GROUP BY m.medicine_id, m.medicine_name
                       HAVING available_qty > 0
                       ORDER BY m.medicine_name";

            // Prepare, bind and execute.
            $in_stmt = $conn->prepare($in_q);
            $in_stmt->bind_param($in_types, ...$in_params);
            $in_stmt->execute();
            $in_items = $in_stmt->get_result();
            ?>
            <!-- Result list. -->
            <?php if ($in_items && $in_items->num_rows > 0): ?>
                <?php while ($item = $in_items->fetch_assoc()): ?>
                    <?php /* Row: medicine name, nearest expiry, available quantity and the Add Stock button. */ ?>
                    <div class="alert-item" style="background:#effaf8;border:1px solid #cdece7;">
                        <span><?= htmlspecialchars($item['medicine_name']) ?></span>
                        <span class="alert-right">
                            <span style="color:#666;font-size:12px;">Nearest expiry: <?= $item['nearest_expiry'] ? htmlspecialchars(date('M Y', strtotime($item['nearest_expiry']))) : '—' ?></span>
                            <span><b><?= (int)$item['available_qty'] ?></b> in stock</span>
                            <button type="button" class="btn-alert-add" data-id="<?= (int)$item['medicine_id'] ?>" data-name="<?= htmlspecialchars($item['medicine_name'], ENT_QUOTES) ?>" onclick="openStockModal(this.dataset.id, this.dataset.name)">Add Stock</button>
                        </span>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <!-- Empty state. -->
                <div style="background:#fff; padding:16px; border-radius:12px; font-weight:600; color:#666;">
                    No in-stock medicines found.
                </div>
            <?php endif; ?>
        </div>
