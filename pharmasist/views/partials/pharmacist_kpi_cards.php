<?php
/**
 * MediVault - Pharmacist module
 * File    : views/partials/pharmacist_kpi_cards.php
 * Title   : KPI cards (Dashboard tab)
 *
 * FRONTEND (HTML)
 * Values come from includes/pharmacist_dashboard_stats.php and includes/pharmacist_page_state.php.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');
?>
    <?php if ($tab === 'all'): ?>
    <!-- Top KPI Dashboard Cards (dashboard tab only) -->
    <div class="kpi-grid">
        <!-- Every KPI card is a link to the matching tab. -->
        <a href="?tab=all" class="kpi-card kpi-card-link">
            <h3><?= $total_meds ?></h3>
            <p>Total Medicine</p>
        </a>
        <a href="?tab=low_stock" class="kpi-card kpi-card-link">
            <h3 style="color: #f97316;"><?= $low_stock ?></h3>
            <p>Low stock alert</p>
        </a>
        <a href="?tab=out_of_stock" class="kpi-card kpi-card-link">
            <h3 style="color: #475569;"><?= $out_of_stock ?></h3>
            <p>Out of stock</p>
        </a>
        <a href="?tab=expiring_soon" class="kpi-card kpi-card-link">
            <h3 style="color: #ef4444;"><?= $expiring ?></h3>
            <p>Expiring in 90 days</p>
        </a>
        <!-- Value is refreshed every 20 seconds by assets/js/pharmacist_live_counts.js (id = kpiReserved). -->
        <a href="?tab=reservation" class="kpi-card kpi-card-link">
            <h3 id="kpiReserved" style="color: #0f766e;"><?= $reserved_req ?></h3>
            <p>Reserved Request</p>
        </a>
        <!-- Value is refreshed live as well (id = kpiNewOrders). -->
        <a href="?tab=order_history" class="kpi-card kpi-card-link new-order-kpi">
            <h3 id="kpiNewOrders" style="color:#078f86;"><?= $new_order_count ?></h3>
            <p>New Orders</p>
        </a>
    </div>
    <?php endif; ?>
