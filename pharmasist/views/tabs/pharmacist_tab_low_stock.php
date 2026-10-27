<?php
/**
 * MediVault - Pharmacist module
 * File    : views/tabs/pharmacist_tab_low_stock.php
 * Title   : Tab: Low Stock (?tab=low_stock)
 *
 * FRONTEND (HTML) + BACKEND (PHP + SQL)
 * Medicines whose free stock is greater than 0 and less than 10.
 * SQL inside this file.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');
?>
        <!-- Single-column list of medicines whose free stock is between 1 and 9 (i.e. >0 and <10). -->
        <div class="single-alert-container">
            <div class="alert-heading" style="color: #475569;">
                ⚠ Low Stock Items
                <span style="font-size: 13px; font-weight: 600; color: #64748b; margin-left: 8px;">
                    (stock 1–9)
                </span>
            </div>
            <?php 
            // SQL: free stock = SUM(quantity_on_hand - reserved_quantity) over the batches of this pharmacy.
            // LEFT JOIN keeps medicines that have no batch (stock = 0) — those are excluded by the HAVING clause.
            // HAVING keeps only current_stock > 0 AND current_stock < 10 (i.e. 1 through 9).
            // Lowest stock first. A LIKE condition is appended below when the pharmacist searches.
            $low_q = "SELECT m.medicine_id, m.medicine_name,
                             IFNULL(SUM(ib.quantity_on_hand - ib.reserved_quantity), 0) AS current_stock,
                             m.reorder_level AS threshold
                      FROM medicines m
                      LEFT JOIN inventory_batches ib
                        ON m.medicine_id = ib.medicine_id AND ib.pharmacy_id = ?
                      WHERE m.is_active = TRUE";
            // Bound parameters: [pharmacy id] (+ search text when searching).
            $low_params = [$pharmacyId];
            $low_types = 'i';

            // Append the search condition (still a prepared statement).
            if (!empty($search)) {
                $low_q .= " AND m.medicine_name LIKE ?";
                $low_types .= 's';
                $low_params[] = "%" . $search . "%";
            }
            // Range filter: show medicines with stock strictly greater than 0 and strictly less than 10.
            $low_q .= " GROUP BY m.medicine_id, m.medicine_name, m.reorder_level
                        HAVING current_stock > 0 AND current_stock < 10
                        ORDER BY current_stock ASC";

            // Prepare, bind (the ... operator spreads the dynamic parameter list) and execute.
            $low_stmt = $conn->prepare($low_q);
            $low_stmt->bind_param($low_types, ...$low_params);
            $low_stmt->execute();
            $low_items = $low_stmt->get_result();
            ?>
            <!-- Result list: one row per low-stock medicine. -->
            <?php if ($low_items && $low_items->num_rows > 0): ?>
                <?php while ($item = $low_items->fetch_assoc()): ?>
                    <?php
                        // Urgency colour by remaining stock (1–3 critical, 4–6 soon, 7–9 watch).
                        $cs = (int)$item['current_stock'];
                        if ($cs <= 3) {
                            $badge_bg   = '#fee2e2';
                            $badge_fg   = '#b91c1c';
                            $badge_line = '#fca5a5';
                            $badge_text = 'Critical';
                        } elseif ($cs <= 6) {
                            $badge_bg   = '#fef3c7';
                            $badge_fg   = '#92400e';
                            $badge_line = '#fcd34d';
                            $badge_text = 'Soon';
                        } else {
                            $badge_bg   = '#e0f2fe';
                            $badge_fg   = '#0369a1';
                            $badge_line = '#bae6fd';
                            $badge_text = 'Watch';
                        }
                    ?>
                    <div class="alert-item alert-out" style="align-items:flex-start;">
                        <div style="display:flex; flex-direction:column; gap:6px; min-width:0;">
                            <span style="font-weight:700; font-size:15px;">
                                <?= htmlspecialchars($item['medicine_name']) ?>
                            </span>
                            <span style="font-size:12px; color:#64748b; font-weight:600;">
                                Reorder level <?= (int)$item['threshold'] ?> · Restock recommended
                            </span>
                        </div>
                        <span class="alert-right" style="gap:12px;">
                            <span style="font-size:12px; color:#475569; font-weight:700;">
                                <?= $cs ?> left
                            </span>
                            <span style="
                                background: <?= $badge_bg ?>;
                                color: <?= $badge_fg ?>;
                                border: 1px solid <?= $badge_line ?>;
                                font-weight: 700;
                                padding: 5px 12px;
                                border-radius: 10px;
                                font-size: 12.5px;
                                min-width: 78px;
                                text-align: center;
                            ">
                                <?= $cs ?>/<?= (int)$item['threshold'] ?> · <?= $badge_text ?>
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
                    No matching low stock items found.
                </div>
            <?php endif; ?>
        </div>