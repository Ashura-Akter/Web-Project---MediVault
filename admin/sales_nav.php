<?php
/*
 * Shared sidebar + helpers for the Admin sales-report pages.
 * Read-only: these pages only run SELECT queries on the existing
 * orders / order_items / medicines / pharmacies tables.
 */
if (!function_exists('sales_money')) {
    function sales_money($x): string { return number_format((float)$x, 2) . ' tk.'; }
}

if (!function_exists('sales_sidebar')) {
    function sales_sidebar(mysqli $conn, string $active): void {
        $pendingCount = (int)$conn->query("SELECT COUNT(*) FROM pharmacist_profiles WHERE approval_status='PENDING'")->fetch_column();
        $cls = static function (string $key) use ($active): string { return $active === $key ? ' class="active"' : ''; };
        ?>
<aside class="admin-sidebar"><nav>
<a href="index.php?tab=all-medicine">▦ <span>Dashboard</span></a>
<a href="index.php?tab=add-medicine">＋ <span>Add Medicine</span></a>
<a href="index.php?tab=stock-expiry">⚠ <span>Stock &amp; Expiry</span></a>
<a href="index.php?tab=pending-approvals">✓ <span>Pharmacist Approvals<?= $pendingCount ? ' (' . $pendingCount . ')' : '' ?></span></a>
<a href="index.php?tab=all-users">♙ <span>All Users</span></a>
<a href="add_admin.php">＋ <span>Add New Admin</span></a>
<a href="index.php?tab=support">? <span>Support Requests</span></a>
<a<?= $cls('daily') ?> href="sales_daily.php">★ <span>Daily Top Sales</span></a>
<a<?= $cls('history') ?> href="sales_history.php">▤ <span>Sales History</span></a>
<a<?= $cls('chart') ?> href="sales_chart.php">% <span>Top Selling Chart</span></a>
</nav></aside>
<script>
/* Sales reports always show live data: reload every 20 seconds while the tab is visible and nobody is typing in a filter. */
(function(){
  setInterval(function(){
    if (document.hidden) return;
    var a=document.activeElement;
    if (a && (a.tagName==='INPUT'||a.tagName==='SELECT')) return;
    location.reload();
  }, 20000);
})();
</script>
        <?php
    }
}
