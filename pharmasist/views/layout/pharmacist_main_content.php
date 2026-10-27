<?php
/**
 * MediVault - Pharmacist module
 * File    : views/layout/pharmacist_main_content.php
 * Title   : Main content wrapper
 *
 * FRONTEND (HTML)
 * Title + success banner, then the search bar, the KPI cards and the active tab view.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');
?>
<!-- Main content area (everything to the right of the sidebar). -->
<div class="container">
    <div class="dashboard-title">Pharmacist Dashboard</div>

    <!-- Green success banner shown after stock was added (?stock_added=1). -->
    <?php if (!empty($_GET['stock_added'])): ?>
        <div class="stock-flash">✅ Stock added. It now appears in the Dashboard with the new expiry date. <a href="medivaultpharmasist.php?tab=all" style="color:#065f46;font-weight:800;">View Dashboard</a></div>
    <?php endif; ?>

    <!-- Search bar -->
    <?php require_once PHARMACIST_ROOT . '/views/partials/pharmacist_search_bar.php'; ?>

    <!-- KPI cards (Dashboard tab only) -->
    <?php require_once PHARMACIST_ROOT . '/views/partials/pharmacist_kpi_cards.php'; ?>

    <!-- Active tab (chosen by ?tab=) -->
    <?php require_once PHARMACIST_ROOT . '/views/tabs/pharmacist_tab_router.php'; ?>
</div>
