<?php
/**
 * MediVault - Pharmacist module
 * File    : views/layout/pharmacist_footer.php
 * Title   : Page footer: viewer modal, JS config and scripts
 *
 * FRONTEND (HTML + JS)
 * Prints the prescription viewer, hands a few PHP values to JavaScript (window.MV_PHARMACIST)
 * and loads the JS files. Closes <body> and <html>.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');
?>
<!-- PRESCRIPTION VIEWER -->
<?php require_once PHARMACIST_ROOT . '/views/modals/pharmacist_modal_rx_viewer.php'; ?>

<!-- PHP values needed by the JS files (this replaces the PHP code that used to sit inside the inline scripts) -->
<script>
    window.MV_PHARMACIST = {
        tab: <?= json_encode($tab) ?>,                 // active tab, used by live search
        badge: <?= (int)$nb_badge ?>,                  // bell badge at page load
        reserved: <?= (int)$reserved_req ?>,           // Reserved Request card at page load
        newOrders: <?= (int)$new_order_count ?>        // New Orders card at page load
    };
</script>

<!-- Page scripts (same order as in the original file) -->
<script src="assets/js/pharmacist_dashboard_core.js"></script>        <!-- profile menu + modals -->
<script src="assets/js/pharmacist_deadline_picker.js"></script>       <!-- Reservations: pickup deadline -->
<script src="assets/js/pharmacist_live_search.js"></script>           <!-- search suggestions -->
<script src="assets/js/pharmacist_live_counts.js"></script>           <!-- live KPI + bell counters -->
<script src="assets/js/pharmacist_rx_viewer.js"></script>             <!-- prescription viewer -->

</body>
</html>
