<?php
session_start(); require_once __DIR__ . '/config.php'; require_once __DIR__ . '/sales_nav.php';

/*
 * Top Selling Medicines: pie chart (pure inline SVG - no external library,
 * works offline in XAMPP). Cancelled orders are not counted.
 * ?by=qty (default) ranks by units sold, ?by=value ranks by sales value,
 * ?by=branch shows each pharmacy branch's share of total sales.
 */
$byParam = (string)($_GET['by'] ?? 'qty');
$by = in_array($byParam, ['value', 'branch'], true) ? $byParam : 'qty';
$isBranch = $by === 'branch';
$topN = 8;

if ($isBranch) {
    $st = $conn->prepare(
        "SELECT p.pharmacy_id, p.pharmacy_name AS medicine_name,
                COUNT(DISTINCT o.order_id) AS orders_count,
                SUM(oi.quantity) AS qty_sold, SUM(oi.line_total) AS amount
         FROM order_items oi
         JOIN orders o     ON o.order_id = oi.order_id
         JOIN pharmacies p ON p.pharmacy_id = o.pharmacy_id
         WHERE o.order_status <> 'CANCELLED'
         GROUP BY p.pharmacy_id, p.pharmacy_name
         ORDER BY amount DESC, p.pharmacy_name ASC"
    );
} else {
    $order = $by === 'value' ? 'amount DESC, qty_sold DESC' : 'qty_sold DESC, amount DESC';
    $st = $conn->prepare(
        "SELECT m.medicine_id, m.medicine_name, SUM(oi.quantity) AS qty_sold, SUM(oi.line_total) AS amount
         FROM order_items oi
         JOIN orders o    ON o.order_id = oi.order_id
         JOIN medicines m ON m.medicine_id = oi.medicine_id
         WHERE o.order_status <> 'CANCELLED'
         GROUP BY m.medicine_id, m.medicine_name
         ORDER BY $order, m.medicine_name ASC"
    );
}
$st->execute(); $all = $st->get_result()->fetch_all(MYSQLI_ASSOC);

// Branch mode always shares by sales value; medicine mode follows the toggle.
$metric = static function (array $r) use ($by): float { return $by === 'qty' ? (float)$r['qty_sold'] : (float)$r['amount']; };
$grand = 0.0; foreach ($all as $r) $grand += $metric($r);

$slices = [];
if ($isBranch) {
    foreach ($all as $r) $slices[] = ['label' => $r['medicine_name'], 'orders' => (int)$r['orders_count'], 'qty' => (int)$r['qty_sold'], 'amount' => (float)$r['amount'], 'v' => $metric($r)];
} else {
    foreach (array_slice($all, 0, $topN) as $r) $slices[] = ['label' => $r['medicine_name'], 'qty' => (int)$r['qty_sold'], 'amount' => (float)$r['amount'], 'v' => $metric($r)];
    if (count($all) > $topN) {
        $oq = 0; $oa = 0.0; $ov = 0.0;
        foreach (array_slice($all, $topN) as $r) { $oq += (int)$r['qty_sold']; $oa += (float)$r['amount']; $ov += $metric($r); }
        $slices[] = ['label' => 'Other medicines (' . (count($all) - $topN) . ')', 'qty' => $oq, 'amount' => $oa, 'v' => $ov];
    }
}
$palette = ['#078f86', '#f97316', '#3b82f6', '#a855f7', '#ef4444', '#eab308', '#14b8a6', '#ec4899', '#94a3b8'];
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MediVault - <?= $isBranch ? 'Sales by Branch' : 'Top Selling Medicines' ?></title><link rel="stylesheet" href="../common/common.css"><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/sales.css"></head><body>
<?php require_once __DIR__ . '/../common/topbar.php'; ?>
<div class="admin-layout"><?php sales_sidebar($conn, 'chart'); ?>
<main class="admin-main">
<div class="sales-head"><div><h1><?= $isBranch ? 'Sales by Branch' : 'Top Selling Medicines' ?></h1><div class="sub"><?= $isBranch ? 'Share of overall sales value by pharmacy branch' : 'Share of overall sales by ' . ($by === 'value' ? 'sales value' : 'quantity sold') . ' (top ' . $topN . ' medicines)' ?></div></div>
<div class="toggle"><a class="<?= $by === 'qty' ? 'on' : '' ?>" href="?by=qty">By quantity</a><a class="<?= $by === 'value' ? 'on' : '' ?>" href="?by=value">By sales value</a><a class="<?= $isBranch ? 'on' : '' ?>" href="?by=branch">By Branch</a></div></div>

<?php if ($slices && $grand > 0): ?>
<div class="chart-wrap">
<svg viewBox="0 0 300 300" role="img" aria-label="<?= $isBranch ? 'Pie chart of sales by branch' : 'Pie chart of top selling medicines' ?>">
<?php
$cx = 150; $cy = 150; $r = 140; $angle = -M_PI / 2;
if (count($slices) === 1) {
    echo '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="' . $palette[0] . '"><title>' . h($slices[0]['label']) . ': 100%</title></circle>';
} else {
    foreach ($slices as $i => $s) {
        $frac = $s['v'] / $grand; if ($frac <= 0) continue;
        $end = $angle + $frac * 2 * M_PI;
        $x1 = $cx + $r * cos($angle); $y1 = $cy + $r * sin($angle);
        $x2 = $cx + $r * cos($end);   $y2 = $cy + $r * sin($end);
        $large = $frac > 0.5 ? 1 : 0;
        $d = sprintf('M%d %d L%.3f %.3f A%d %d 0 %d 1 %.3f %.3f Z', $cx, $cy, $x1, $y1, $r, $r, $large, $x2, $y2);
        $tip = $s['label'] . ': ' . ($isBranch ? $s['orders'] . ' orders, ' : '') . $s['qty'] . ' units, ' . sales_money($s['amount']) . ' (' . number_format($frac * 100, 1) . '%)';
        echo '<path d="' . $d . '" fill="' . $palette[$i % count($palette)] . '"><title>' . h($tip) . '</title></path>';
        $angle = $end;
    }
}
?>
</svg>
<div><table class="legend">
<thead><tr><th><?= $isBranch ? 'Branch' : 'Medicine' ?></th><?php if ($isBranch): ?><th class="num">Orders</th><?php endif; ?><th class="num">Qty Sold</th><th class="num">Sales Value</th><th class="num">Share</th></tr></thead>
<tbody>
<?php foreach ($slices as $i => $s): ?>
<tr><td><span class="swatch" style="background:<?= h($palette[$i % count($palette)]) ?>"></span><?= h($s['label']) ?></td><?php if ($isBranch): ?><td class="num"><?= (int)$s['orders'] ?></td><?php endif; ?><td class="num"><?= (int)$s['qty'] ?></td><td class="num"><?= h(sales_money($s['amount'])) ?></td><td class="num"><b><?= number_format($s['v'] / $grand * 100, 1) ?>%</b></td></tr>
<?php endforeach; ?>
</tbody></table></div>
</div>
<?php else: ?>
<div class="sales-empty">No sales data available yet to build the chart.</div>
<?php endif; ?>
</main></div></body></html>
