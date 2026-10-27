<?php
/**
 * MediVault - Pharmacist module
 * File    : views/tabs/pharmacist_tab_out_of_stock.php
 * Title   : Tab: Out of Stock (?tab=out_of_stock)
 *
 * FRONTEND (HTML) + BACKEND (PHP + SQL)
 * Medicines with no usable (non expired) stock.
 * SQL inside this file.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');
?>
        <!-- List of medicines that are completely out of stock in this pharmacy. -->
        <div class="single-alert-container">
            <div class="alert-heading" style="color: #475569;">
                ⊘ Out of Stock Items
                <span style="font-size: 13px; font-weight: 600; color: #64748b; margin-left: 8px;">
                    (no usable stock)
                </span>
            </div>
            <?php
            // SQL: active medicines whose NOT expired free quantity sums to 0.
            // Expired batches are ignored with CASE WHEN expiry_date > CURDATE(). GREATEST(...,0) avoids negative numbers.
            $out_q = "SELECT m.medicine_id, m.medicine_name, m.reorder_level AS threshold
                      FROM medicines m
                      LEFT JOIN inventory_batches ib
                        ON m.medicine_id = ib.medicine_id AND ib.pharmacy_id = ?
                      WHERE m.is_active = TRUE";
            // Bound parameters: [pharmacy id] (+ search text when searching).
            $out_params = [$pharmacyId];
            $out_types = 'i';

            // Append the search condition.
            if ($search !== '') {
                $out_q .= " AND m.medicine_name LIKE ?";
                $out_types .= 's';
                $out_params[] = "%" . $search . "%";
            }
            $out_q .= " GROUP BY m.medicine_id, m.medicine_name, m.reorder_level
                        HAVING COALESCE(SUM(CASE WHEN ib.expiry_date > CURDATE() THEN GREATEST(ib.quantity_on_hand - ib.reserved_quantity, 0) ELSE 0 END), 0) = 0
                        ORDER BY m.medicine_name";

            // Prepare, bind and execute.
            $out_stmt = $conn->prepare($out_q);
            $out_stmt->bind_param($out_types, ...$out_params);
            $out_stmt->execute();
            $out_items = $out_stmt->get_result();
            ?>
            <!-- Result list. -->
            <?php if ($out_items && $out_items->num_rows > 0): ?>
                <?php while ($item = $out_items->fetch_assoc()): ?>
                    <div class="alert-item alert-out" style="align-items:flex-start;">
                        <div style="display:flex; flex-direction:column; gap:6px; min-width:0;">
                            <span style="font-weight:700; font-size:15px;">
                                <?= htmlspecialchars($item['medicine_name']) ?>
                            </span>
                            <span style="font-size:12px; color:#64748b; font-weight:600;">
                                Reorder level <?= (int)$item['threshold'] ?> · Restock required
                            </span>
                        </div>
                        <span class="alert-right" style="gap:12px;">
                            <span style="font-size:12px; color:#b91c1c; font-weight:700;">
                                0 available
                            </span>
                            <span style="
                                background: #fee2e2;
                                color: #b91c1c;
                                border: 1px solid #fca5a5;
                                font-weight: 700;
                                padding: 5px 12px;
                                border-radius: 10px;
                                font-size: 12.5px;
                                min-width: 78px;
                                text-align: center;
                            ">
                                0/<?= (int)$item['threshold'] ?> · Critical
                            </span>
                            <button
                                type="button"
                                class="btn-alert-add"
                                data-id="<?= (int)$item['medicine_id'] ?>"
                                data-name="<?= htmlspecialchars($item['medicine_name'], ENT_QUOTES) ?>"
                                onclick="openStockModal(this.dataset.id, this.dataset.name)">Add Stock</button>
                        </span>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <!-- Empty state. -->
                <div style="background:#fff; padding:16px; border-radius:12px; font-weight:600; color:#666;">
                    No out of stock medicines found.
                </div>
            <?php endif; ?>
        </div>