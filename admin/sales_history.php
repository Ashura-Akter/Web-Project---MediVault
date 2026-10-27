<?php
session_start(); require_once __DIR__ . '/config.php'; require_once __DIR__ . '/sales_nav.php';

/*
 * Overall Sales History: every sold medicine line (order_items) with
 * date, medicine, quantity and amount. Cancelled orders are not counted.
 */
$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));

$validDate = static function ($v): string {
    $v = trim((string)$v);
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : '';
};
$from = $validDate($_GET['from'] ?? '');
$to   = $validDate($_GET['to'] ?? '');

/* Pharmacy branch filter: options come from the existing pharmacies table. 0 = All Branches. */
$branches = $conn->query("SELECT pharmacy_id, pharmacy_name FROM pharmacies ORDER BY pharmacy_name ASC")->fetch_all(MYSQLI_ASSOC);
$branch = (int)($_GET['branch'] ?? 0);
if (!in_array($branch, array_map('intval', array_column($branches, 'pharmacy_id')), true)) $branch = 0;

$where = "o.order_status <> 'CANCELLED'"; $types = ''; $params = [];
if ($from !== '') { $where .= " AND o.placed_at >= ?"; $types .= 's'; $params[] = $from . ' 00:00:00'; }
if ($to   !== '') { $where .= " AND o.placed_at < DATE_ADD(?, INTERVAL 1 DAY)"; $types .= 's'; $params[] = $to; }
if ($branch > 0)  { $where .= " AND o.pharmacy_id = ?"; $types .= 'i'; $params[] = $branch; }

$from_sql = "FROM order_items oi
             JOIN orders o      ON o.order_id = oi.order_id
             JOIN medicines m   ON m.medicine_id = oi.medicine_id
             JOIN pharmacies ph ON ph.pharmacy_id = o.pharmacy_id
             WHERE $where";

$st = $conn->prepare("SELECT COUNT(*) AS records, COALESCE(SUM(oi.quantity),0) AS qty, COALESCE(SUM(oi.line_total),0) AS amount $from_sql");
if ($types) $st->bind_param($types, ...$params);
$st->execute(); $sum = $st->get_result()->fetch_assoc();

$records = (int)$sum['records'];
$pages = max(1, (int)ceil($records / $perPage));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $perPage;

$st = $conn->prepare(
    "SELECT o.placed_at, o.order_number, ph.pharmacy_name, m.medicine_name, oi.quantity, oi.unit_price, oi.line_total
     $from_sql
     ORDER BY o.placed_at DESC, oi.order_item_id DESC
     LIMIT ? OFFSET ?"
);
$t2 = $types . 'ii'; $p2 = array_merge($params, [$perPage, $offset]);
$st->bind_param($t2, ...$p2);
$st->execute(); $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);

$qs = static function (int $p) use ($from, $to, $branch): string {
    $a = ['page' => $p]; if ($from !== '') $a['from'] = $from; if ($to !== '') $a['to'] = $to; if ($branch > 0) $a['branch'] = $branch;
    return '?' . http_build_query($a);
};
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MediVault - Sales History</title><link rel="stylesheet" href="../common/common.css"><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/sales.css"></head><body>
<?php require_once __DIR__ . '/../common/topbar.php'; ?>
<div class="admin-layout"><?php sales_sidebar($conn, 'history'); ?>
<main class="admin-main">
<div class="sales-head"><div><h1>Overall Sales History</h1><div class="sub">Complete sales records, newest first</div></div></div>

<section class="sales-metrics">
<div><strong><?= $records ?></strong><span>Sales records</span></div>
<div><strong><?= (int)$sum['qty'] ?></strong><span>Total units sold</span></div>
<div><strong><?= h(sales_money($sum['amount'])) ?></strong><span>Total sales value</span></div>
</section>

<form class="sales-filter" method="get">
<div><label for="branch">Pharmacy Branch</label><select id="branch" name="branch"><option value="0">All Branches</option><?php foreach ($branches as $b): ?><option value="<?= (int)$b['pharmacy_id'] ?>"<?= $branch === (int)$b['pharmacy_id'] ? ' selected' : '' ?>><?= h($b['pharmacy_name']) ?></option><?php endforeach; ?></select></div>
<div><label for="from">From date</label><input type="date" id="from" name="from" value="<?= h($from) ?>"></div>
<div><label for="to">To date</label><input type="date" id="to" name="to" value="<?= h($to) ?>"></div>
<button class="sales-btn" type="submit">Filter</button>
<?php if ($from !== '' || $to !== '' || $branch > 0): ?><a class="sales-btn light" href="sales_history.php">Clear</a><?php endif; ?>
</form>

<?php if ($rows): ?>
<div class="sales-card"><table class="sales-table">
<thead><tr><th>Date</th><th>Order No.</th><th>Pharmacy</th><th>Medicine</th><th class="num">Qty Sold</th><th class="num">Total Amount</th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr><td class="muted-cell"><?= h(date('d/m/Y h:i A', strtotime($r['placed_at']))) ?></td><td class="muted-cell"><?= h($r['order_number']) ?></td><td class="muted-cell"><?= h($r['pharmacy_name']) ?></td><td><b><?= h($r['medicine_name']) ?></b></td><td class="num"><?= (int)$r['quantity'] ?></td><td class="num"><?= h(sales_money($r['line_total'])) ?></td></tr>
<?php endforeach; ?>
</tbody>
</table></div>

<?php if ($pages > 1): ?>
<nav class="pager" aria-label="Pages">
<?php if ($page > 1): ?><a href="<?= h($qs($page - 1)) ?>">&laquo; Prev</a><?php endif; ?>
<?php
$shown = [];
for ($i = 1; $i <= $pages; $i++) { if ($i === 1 || $i === $pages || abs($i - $page) <= 2) $shown[] = $i; }
$prev = 0;
foreach ($shown as $i) {
    if ($prev && $i - $prev > 1) echo '<span class="gap">…</span>';
    echo $i === $page ? '<span class="cur">' . $i . '</span>' : '<a href="' . h($qs($i)) . '">' . $i . '</a>';
    $prev = $i;
}
?>
<?php if ($page < $pages): ?><a href="<?= h($qs($page + 1)) ?>">Next &raquo;</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php else: ?>
<div class="sales-empty">No sales records found<?= ($from !== '' || $to !== '' || $branch > 0) ? ' for the selected filters' : '' ?>.</div>
<?php endif; ?>
</main></div></body></html>
