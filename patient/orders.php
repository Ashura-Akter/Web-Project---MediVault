<?php
session_start();
require_once __DIR__ . '/../database/queries.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login/login.php');
    exit;
}
$userId = (int)$_SESSION['user_id'];
ensure_checkout_schema($conn);
mark_notifications_read($conn, $userId, ['ORDER_UPDATE', 'PAYMENT_UPDATE']);

$orders = get_orders($conn, $userId);
usort($orders, fn($a, $b) => strtotime($b['placed_at']) <=> strtotime($a['placed_at']));
?>
<?php require_once __DIR__ . '/../common/header.php'; ?>
<style>
    .order-page .history-title {
        font-size: 28px;
        font-weight: 400;
        margin: 5px 0 12px
    }

    .order-page .table-wrap {
        overflow: auto
    }

    .order-page .history-table {
        width: 100%;
        border-collapse: collapse;
        background: #fff;
        font-size: 14px
    }

    .order-page .history-table th,
    .order-page .history-table td {
        padding: 10px 9px;
        border-bottom: 1px solid #eee;
        text-align: left;
        vertical-align: middle
    }

    .order-page .history-table td[rowspan] {
        vertical-align: top
    }

    .order-page .history-table th {
        font-size: 14px;
        font-weight: 600;
        background: #fff;
        border-bottom: 1px solid #ddd
    }

    .order-page .history-table tbody tr.row-bg-white td {
        background: #ffffff
    }

    .order-page .history-table tbody tr.row-bg-gray td {
        background: #f1f1f1
    }

    .order-page .history-table td {
        font-size: 14px;
        color: #222;
        line-height: 1.4
    }

    .order-page .status {
        font-size: 13px;
        padding: 3px 7px
    }

    .order-page .reorder-btn {
        font-size: 12px;
        padding: 7px 10px
    }

    .order-page .icon-btn {
        width: 34px;
        height: 34px;
        display: inline-grid;
        place-items: center;
        border: 0;
        background: #e6f5f2;
        color: #078f86;
        border-radius: 9px;
        text-decoration: none;
        cursor: pointer
    }

    .order-page .icon-btn:hover {
        background: #cfeae7
    }

    .order-page .icon-btn svg {
        width: 18px;
        height: 18px
    }

    .order-page .cell-med {
        font-weight: 600
    }

    .order-page .cell-qty {
        text-align: center;
        font-weight: 700;
        color: #0f766e
    }

    @media(max-width:900px) {
        .order-page .history-table {
            min-width: 980px
        }
    }
</style>
<div class="order-page">
    <div class="panel" style="background:transparent;padding:0;margin-bottom:4px">
        <h2 class="history-title">Order History</h2>
    </div>
    <?php if (!$orders): ?>
        <div class="empty">No orders yet. Browse medicines and place your first order.</div>
    <?php else: ?>
        <div class="panel" style="padding:0;background:transparent">
            <div class="table-wrap">
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Order Date</th>
                            <th>Medicine Name</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Total Price</th>
                            <th>Order Status</th>
                            <th>Payment Status</th>
                            <th>Invoice</th>
                            <th>Re-Order</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $groupIndex = 0; ?>
                        <?php foreach ($orders as $row): ?>
                            <?php
                            $details = get_order_details($conn, $userId, (int)$row['order_id']);
                            $items = $details['items'] ?? [];
                            if (empty($items)) $items = [['medicine_name' => '—', 'strength' => '', 'quantity' => 0, 'unit_price' => 0, 'line_total' => 0]];
                            $itemCount = count($items);
                            $rowBg = ($groupIndex % 2 === 0) ? 'row-bg-white' : 'row-bg-gray';
                            ?>
                            <?php foreach ($items as $idx => $item): ?>
                                <tr class="<?= $rowBg ?>">
                                    <?php if ($idx === 0): ?>
                                        <td rowspan="<?= $itemCount ?>" style="font-weight:700;"><?= htmlspecialchars($row['order_number']) ?></td>
                                        <td rowspan="<?= $itemCount ?>"><?= date('d/m/Y', strtotime($row['placed_at'])) ?></td>
                                    <?php endif; ?>
                                    <td class="cell-med"><?= htmlspecialchars(trim($item['medicine_name'] . ' ' . ($item['strength'] ?? ''))) ?></td>
                                    <td class="cell-qty"><?= (int)$item['quantity'] ?></td>
                                    <td><?= number_format((float)$item['unit_price'], 0) ?></td>
                                    <td><?= number_format((float)$item['line_total'], 0) ?></td>
                                    <?php if ($idx === 0): ?>
                                        <td rowspan="<?= $itemCount ?>"><span class="status"><?= htmlspecialchars($row['order_status']) ?></span></td>
                                        <td rowspan="<?= $itemCount ?>"><?= htmlspecialchars($row['payment_status'] ?? '—') ?></td>
                                        <td rowspan="<?= $itemCount ?>"><a class="icon-btn" title="View Invoice" href="invoice.php?id=<?= (int)$row['order_id'] ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M7 3h10v18H7z" />
                                                    <path d="M9 7h6M9 11h6M9 15h4" />
                                                </svg></a></td>
                                        <td rowspan="<?= $itemCount ?>">
                                            <form method="post" action="actions/reorder.php" style="margin:0;"><input type="hidden" name="order_id" value="<?= (int)$row['order_id'] ?>"><button class="reorder-btn" type="submit">Re-Order</button></form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            <?php $groupIndex++; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../common/footer.php'; ?>