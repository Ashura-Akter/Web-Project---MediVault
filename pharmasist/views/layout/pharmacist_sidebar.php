<?php
/**
 * MediVault - Pharmacist module
 * File    : views/layout/pharmacist_sidebar.php
 * Title   : Left sidebar navigation
 *
 * FRONTEND (HTML)
 * One link per tab: Dashboard, In Stock, Low Stock, Out of Stock, Expiring Soon, Pending, Approved,
 * Rejected, Reservations, Reservation History, Order History.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');
?>
<!-- Left navigation: one link per tab. The class 'active' is added when $tab matches the link. -->
<aside class="role-sidebar">
    <nav>
        <a class="<?= $tab === 'all' ? 'active' : '' ?>" href="medivaultpharmasist.php?tab=all">▦ <span>Dashboard</span></a>
        <a class="<?= $tab === 'in_stock' ? 'active' : '' ?>" href="medivaultpharmasist.php?tab=in_stock">✔ <span>In Stock</span></a>
        <a class="<?= $tab === 'low_stock' ? 'active' : '' ?>" href="medivaultpharmasist.php?tab=low_stock">⚠ <span>Low Stock</span></a>
        <a class="<?= $tab === 'out_of_stock' ? 'active' : '' ?>" href="medivaultpharmasist.php?tab=out_of_stock">⊘ <span>Out of Stock</span></a>
        <a class="<?= $tab === 'expiring_soon' ? 'active' : '' ?>" href="medivaultpharmasist.php?tab=expiring_soon">◷ <span>Expiring Soon</span></a>
        <a class="<?= $tab === 'pending' ? 'active' : '' ?>" href="medivaultpharmasist.php?tab=pending">⏳ <span>Pending</span></a>
        <a class="<?= $tab === 'approved' ? 'active' : '' ?>" href="medivaultpharmasist.php?tab=approved">✓ <span>Approved</span></a>
        <a class="<?= $tab === 'rejected' ? 'active' : '' ?>" href="medivaultpharmasist.php?tab=rejected">✕ <span>Rejected</span></a>
        <a class="<?= $tab === 'reservation' ? 'active' : '' ?>" href="medivaultpharmasist.php?tab=reservation">▣ <span>Reservations</span></a>
        <a class="<?= $tab === 'res_history' ? 'active' : '' ?>" href="medivaultpharmasist.php?tab=res_history">◴ <span>Reservation History</span></a>
        <a class="<?= $tab === 'order_history' ? 'active' : '' ?>" href="medivaultpharmasist.php?tab=order_history">▤ <span>Order History</span></a>
    </nav>
</aside>
