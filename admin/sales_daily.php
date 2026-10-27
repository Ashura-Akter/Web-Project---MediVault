<?php
session_start(); require_once __DIR__ . '/config.php'; require_once __DIR__ . '/sales_nav.php';

/*
 * Daily Top Pharmacy Sales: medicines sold today (all pharmacies),
 * highest selling first. Cancelled orders are not counted.
 */
$stmt = $conn->prepare(
    "SELECT m.medicine_id, m.medicine_name,
            SUM(oi.quantity)   AS qty_sold,
            SUM(oi.line_total) AS amount,
            COUNT(DISTINCT o.order_id) AS order_count,
            GROUP_CONCAT(DISTINCT ph.pharmacy_name ORDER BY ph.pharmacy_name SEPARATOR ', ') AS pharmacies
     FROM order_items oi
     JOIN orders o     ON o.order_id = oi.order_id
     JOIN medicines m  ON m.medicine_id = oi.medicine_id
     JOIN pharmacies ph ON ph.pharmacy_id = o.pharmacy_id
     WHERE o.placed_at >= CURDATE()
       AND o.placed_at <  CURDATE() + INTERVAL 1 DAY
       AND o.order_status <> 'CANCELLED'
     GROUP BY m.medicine_id, m.medicine_name
     ORDER BY qty_sold DESC, amount DESC, m.medicine_name ASC"
);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$totalQty = 0; $totalAmount = 0.0;
foreach ($rows as $r) { $totalQty += (int)$r['qty_sold']; $totalAmount += (float)$r['amount']; }
$today = (string)$conn->query("SELECT CURDATE()")->fetch_column();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MediVault - Daily Top Sales</title><link rel="stylesheet" href="../common/common.css"><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/sales.css"></head><body>
<?php require_once __DIR__ . '/../common/topbar.php'; ?>
<div class="admin-layout"><?php sales_sidebar($conn, 'daily'); ?>
<main class="admin-main">
<div class="sales-head"><div><h1>Daily Top Pharmacy Sales</h1><div class="sub">Medicines sold today (<?= h(date('l, d M Y', strtotime($today))) ?>), highest selling first</div></div></div>

<section class="sales-metrics">
<div><strong><?= count($rows) ?></strong><span>Different medicines sold today</span></div>
<div><strong><?= (int)$totalQty ?></strong><span>Total units sold today</span></div>
<div><strong><?= h(sales_money($totalAmount)) ?></strong><span>Total sales value today</span></div>
</section>

<?php if ($rows): ?>
<div class="sales-card"><table class="sales-table">
<thead><tr><th>Rank</th><th>Medicine</th><th>Pharmacy</th><th class="num">Orders</th><th class="num">Qty Sold</th><th class="num">Total Amount</th></tr></thead>
<tbody>
<?php foreach ($rows as $i => $r): ?>
<tr><td><span class="rank <?= $i === 0 ? 'r1' : '' ?>"><?= $i + 1 ?></span></td><td><b><?= h($r['medicine_name']) ?></b></td><td class="muted-cell"><?= h($r['pharmacies']) ?></td><td class="num"><?= (int)$r['order_count'] ?></td><td class="num"><b><?= (int)$r['qty_sold'] ?></b></td><td class="num"><?= h(sales_money($r['amount'])) ?></td></tr>
<?php endforeach; ?>
</tbody>
<tfoot><tr><td colspan="4">Total</td><td class="num"><?= (int)$totalQty ?></td><td class="num"><?= h(sales_money($totalAmount)) ?></td></tr></tfoot>
</table></div>
<?php else: ?>
<div class="sales-empty">No medicines have been sold yet today.</div>
<?php endif; ?>
</main></div></body></html>
