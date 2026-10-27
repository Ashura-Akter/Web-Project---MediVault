<?php
/**
 * MediVault - Pharmacist module
 * File    : views/tabs/pharmacist_tab_expiring_soon.php
 * Title   : Tab: Expiring Soon (?tab=expiring_soon)
 *
 * FRONTEND (HTML) + BACKEND (PHP + SQL)
 * Medicines with a batch that expires within 90 days.
 * SQL inside this file.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');
?>
        <!-- List of medicines with a batch that expires within 90 days. -->
        <div class="single-alert-container">
            <div class="alert-heading" style="color: #ef4444;">
                🔴 Expiring Soon
                <span style="font-size: 13px; font-weight: 600; color: #64748b; margin-left: 8px;">
                    (within 90 days)
                </span>
            </div>
            <?php 
            // SQL: for every batch of this pharmacy inside the next 90 days, take the earliest expiry
            // and turn it into days_left with DATEDIFF. The most urgent batch comes first.
            // Batch number and current quantity are included so the pharmacist knows which batch is expiring.
            $exp_q = "SELECT
                          m.medicine_id,
                          m.medicine_name,
                          ib.batch_no,
                          ib.quantity_on_hand,
                          ib.expiry_date,
                          DATEDIFF(ib.expiry_date, CURDATE()) AS days_left
                      FROM medicines m
                      JOIN inventory_batches ib ON m.medicine_id = ib.medicine_id
                      WHERE m.is_active = TRUE
                        AND ib.pharmacy_id = ?
                        AND ib.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
                        AND ib.quantity_on_hand > 0";
            // Bound parameters: [pharmacy id] (+ search text when searching).
            $exp_params = [$pharmacyId];
            $exp_types = 'i';

            // Append the search condition.
            if (!empty($search)) {
                $exp_q .= " AND m.medicine_name LIKE ?";
                $exp_types .= 's';
                $exp_params[] = "%" . $search . "%";
            }
            $exp_q .= " ORDER BY days_left ASC, m.medicine_name ASC LIMIT 200";

            // Prepare, bind and execute.
            $exp_stmt = $conn->prepare($exp_q);
            $exp_stmt->bind_param($exp_types, ...$exp_params);
            $exp_stmt->execute();
            $exp_items = $exp_stmt->get_result();
            ?>
            <!-- Result list. -->
            <?php if ($exp_items && $exp_items->num_rows > 0): ?>
                <?php while ($item = $exp_items->fetch_assoc()): ?>
                    <?php
                        // Urgency level based on days remaining.
                        $d = (int)$item['days_left'];
                        if ($d <= 7) {
                            $badge_bg   = '#fee2e2';
                            $badge_fg   = '#b91c1c';
                            $badge_line = '#fca5a5';
                            $badge_text = 'Critical';
                        } elseif ($d <= 30) {
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
                                Batch <?= htmlspecialchars($item['batch_no']) ?>
                                &nbsp;·&nbsp; Expires <?= date('d M Y', strtotime($item['expiry_date'])) ?>
                            </span>
                        </div>
                        <span class="alert-right" style="gap:12px;">
                            <span style="font-size:12px; color:#475569; font-weight:700;">
                                Qty <?= (int)$item['quantity_on_hand'] ?>
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
                                <?= $d ?>d · <?= $badge_text ?>
                            </span>
                        </span>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <!-- Empty state. -->
                <div style="background:#fff; padding:16px; border-radius:12px; font-weight:600; color:#666;">
                    No matching expiring items found.
                </div>
            <?php endif; ?>
        </div>