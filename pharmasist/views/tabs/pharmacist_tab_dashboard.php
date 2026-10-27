<?php
/**
 * MediVault - Pharmacist module
 * File    : views/tabs/pharmacist_tab_dashboard.php
 * Title   : Tab: Dashboard (?tab=all)
 *
 * FRONTEND (HTML) + BACKEND (PHP + SQL)
 * Medicine cards with price, expiry, quantity and the Add Stock button.
 * Data: $medicines_result from includes/pharmacist_medicine_query.php.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');
?>
        <!-- Grid of medicine cards: one card per medicine + expiry date + price (a new expiry date creates a new card). -->
        <div class="med-grid">
            <!-- Data comes from includes/pharmacist_medicine_query.php ($medicines_result). -->
            <?php if ($medicines_result && $medicines_result->num_rows > 0): ?>
                <?php while ($med = $medicines_result->fetch_assoc()): ?>
                    <?php 
                        // Per-card values: stock quantity, 'low stock' flag (total <= 20) and formatted expiry month.
                        $qty = (int)$med['total_qty'];
                        $is_low = (int)($med['med_total_qty'] ?? 0) <= 20;
                        $expiry_formatted = $med['min_expiry'] ? date('M Y', strtotime($med['min_expiry'])) : 'N/A';
                        
                        // Calculate days until expiry
                        $days_to_expiry = null;
                        if ($med['min_expiry']) {
                            $today = new DateTime();
                            $exp_date = new DateTime($med['min_expiry']);
                            $days_to_expiry = (int)$today->diff($exp_date)->format("%r%a");
                        }
                        
                        // Red highlight if expiring in less than 10 days
                        $is_expiring_soon = ($days_to_expiry !== null && $days_to_expiry <= 10);
                    ?>
                    <?php /* One medicine card. */ ?>
                    <div class="med-card">
                        <div>
                            <?php /* medicine_image_html() is a helper from database/queries.php. */ ?>
                            <?= medicine_image_html($med) ?>
                            <div class="category-name"><?= htmlspecialchars($med['category_name'] ?? 'General') ?></div>
                            <div class="med-price"><?= $med['stock_price'] !== null ? number_format((float)$med['stock_price'], 0) : '—' ?>tk.</div>
                            <div class="med-title"><?= htmlspecialchars($med['name'] ?? $med['medicine_name']) ?></div>
                            <div class="status-row">
                                <?php if ($is_low): ?>
                                    <span class="badge-lowstock">Low stock</span>
                                <?php else: ?>
                                    <span class="badge-instock">In stock</span>
                                <?php endif; ?>
                                
                                <!-- Highlighted Expiry Date (Red if <= 10 days) -->
                                <span style="background: <?= $is_expiring_soon ? '#fee2e2' : '#e0f2fe' ?>; color: <?= $is_expiring_soon ? '#dc2626' : '#0369a1' ?>; font-weight: 700; padding: 4px 10px; border-radius: 8px; font-size: 0.85rem; border: 1px solid <?= $is_expiring_soon ? '#fca5a5' : '#bae6fd' ?>;">
                                    Ex. <?= $expiry_formatted ?>
                                </span>
                                <?php if ((int)($med['is_restricted'] ?? 0) === 1): ?>
                                    <span style="background:#fee2e2;color:#dc2626;font-weight:700;padding:4px 10px;border-radius:8px;font-size:0.85rem;border:1px solid #fca5a5;">Restricted</span>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Highlighted Company & QTY Row -->
                            <div class="meta-row" style="margin-top: 10px; display: flex; gap: 8px; flex-wrap: wrap;">
                                <span style="background: #f3f4f6; color: #1f2937; font-weight: 600; padding: 4px 8px; border-radius: 6px; font-size: 0.85rem;">
                                    🏢 <?= htmlspecialchars($med['company'] ?? $med['company_name'] ?? 'N/A') ?>
                                </span>
                                <span style="background: #ecfeff; color: #0e7490; font-weight: 600; padding: 4px 8px; border-radius: 6px; font-size: 0.85rem;">
                                    💊 <?= htmlspecialchars(medicine_form_label($med['dosage_form'] ?? '')) ?>
                                </span>
                                <span style="background: #eef2ff; color: #4338ca; font-weight: 700; padding: 4px 8px; border-radius: 6px; font-size: 0.85rem;">
                                    QTY <?= $qty ?>
                                </span>
                            </div>
                            
                            <div class="med-desc" style="margin-top: 8px;"><?= htmlspecialchars($med['description'] ?? '') ?></div>
                        </div>
                        <?php /* Add Stock button: opens the Add Stock modal (openStockModal() in pharmacist_dashboard_core.js). */ ?>
                        <div class="med-actions">
                            <button class="btn-action-green" style="width:100%;" onclick="openStockModal(<?= $med['medicine_id'] ?>, '<?= htmlspecialchars($med['name'] ?? $med['medicine_name'], ENT_QUOTES) ?>')">Add Stock</button>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <!-- Empty state: no medicine matches the search. -->
                <div style="grid-column: span 3; text-align: center; padding: 40px; background: #fff; border-radius: 20px; font-weight: 600; color: #666;">
                    No medicines found matching "<?= htmlspecialchars($search) ?>"
                </div>
            <?php endif; ?>
        </div>
