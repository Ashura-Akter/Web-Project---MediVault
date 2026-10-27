<?php
session_start();
require_once __DIR__ . '/../database/queries.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: orders.php');
    exit;
}
ensure_checkout_schema($conn);
$uid = (int)$_SESSION['user_id'];
$reservationId = (int)($_GET['reservation_id'] ?? 0);
$id = (int)($_GET['id'] ?? 0);
$uStmt = $conn->prepare("SELECT full_name,email,phone,address FROM users WHERE user_id=?");
$uStmt->bind_param('i', $uid);
$uStmt->execute();
$u = $uStmt->get_result()->fetch_assoc() ?: [];
if ($reservationId > 0) {
    $st = $conn->prepare("SELECT r.*,p.pharmacy_name,p.campus_name,
   GROUP_CONCAT(CONCAT(m.medicine_name,' ',COALESCE(m.strength,'')) ORDER BY m.medicine_name SEPARATOR '|||') medicines,
   GROUP_CONCAT(ri.quantity ORDER BY m.medicine_name SEPARATOR '|||') quantities,
   rx.prescription_number,rx.file_path,rx.file_type,rx.uploaded_at,rx.status rx_status,rx.pharmacist_note
   FROM reservations r JOIN pharmacies p ON p.pharmacy_id=r.pharmacy_id
   JOIN reservation_items ri ON ri.reservation_id=r.reservation_id
   JOIN medicines m ON m.medicine_id=ri.medicine_id
   LEFT JOIN prescriptions rx ON rx.prescription_id=r.prescription_id
   WHERE r.reservation_id=? AND r.patient_id=?
   GROUP BY r.reservation_id,p.pharmacy_name,rx.prescription_number,rx.file_path,rx.file_type,rx.uploaded_at,rx.status,rx.pharmacist_note LIMIT 1");
    $st->bind_param('ii', $reservationId, $uid);
    $st->execute();
    $reservation = $st->get_result()->fetch_assoc();
    if (!$reservation) {
        header('Location: orders.php');
        exit;
    }
    $invMeds = array_values(array_filter(explode('|||', (string)$reservation['medicines']), fn($v) => trim($v) !== ''));
    $invQtys = explode('|||', (string)$reservation['quantities']);
} else {
    $order = get_order_details($conn, $uid, $id);
    if (!$order) {
        header('Location: orders.php');
        exit;
    }
    $pStmt = $conn->prepare("SELECT payment_method,payment_provider,payment_account,payment_status,amount,transaction_reference,paid_at FROM payments WHERE order_id=? LIMIT 1");
    $pStmt->bind_param('i', $id);
    $pStmt->execute();
    $payment = $pStmt->get_result()->fetch_assoc() ?: [];
}
?>
<?php require_once __DIR__ . '/../common/header.php'; ?>
<style>
    .invoice {
        max-width: 850px;
        margin: 0 auto;
        background: #fff;
        border: 1px solid #aaa;
        padding: 26px
    }

    .invoice h1 {
        text-align: center;
        margin: 0 0 6px
    }

    .invoice .meta {
        display: flex;
        justify-content: space-between;
        gap: 20px;
        border-bottom: 1px solid #aaa;
        padding-bottom: 14px;
        margin-bottom: 18px
    }

    .invoice table {
        width: 100%;
        border-collapse: collapse
    }

    .invoice th,
    .invoice td {
        border-bottom: 1px solid #ddd;
        padding: 10px;
        text-align: left
    }

    .invoice th {
        background: #f7fbfa;
        font-size: 13px
    }

    .invoice .inv-qty {
        text-align: center;
        font-weight: 700
    }

    .invoice .totals {
        margin-left: auto;
        width: 320px;
        margin-top: 15px
    }

    .invoice .totals div {
        display: flex;
        justify-content: space-between;
        padding: 5px
    }

    .invoice .total {
        font-size: 20px;
        font-weight: 700;
        border-top: 1px solid #777;
        margin-top: 5px;
        padding-top: 9px
    }

    .invoice .info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px 30px;
        margin-bottom: 18px
    }

    .invoice .print {
        margin-top: 20px;
        text-align: center
    }

    .invoice .print button {
        background: #07988f;
        color: #fff;
        border: 0;
        padding: 10px 18px;
        border-radius: 8px;
        font-weight: 700
    }

    .invoice .document {
        margin-top: 20px;
        padding: 14px;
        background: #f3f8f7;
        border: 1px solid #d7ebe8;
        border-radius: 10px
    }

    .invoice .document a {
        color: #078f86;
        font-weight: 700;
        text-decoration: none
    }

    @media print {

        .mv-topbar,
        .sidebar,
        .print {
            display: none !important
        }

        .content {
            margin: 0 !important;
            padding: 0 !important
        }

        .invoice {
            border: 0
        }
    }
</style>
<?php if ($reservationId > 0): ?>
    <div class="invoice">
        <h1>MediVault Reservation Invoice</h1>
        <div style="text-align:center;color:#666;margin-bottom:18px">Reservation <?= htmlspecialchars($reservation['reservation_number']) ?></div>
        <div class="meta">
            <div><strong>Requested Date &amp; Time</strong><br><?= date('d/m/Y h:i A', strtotime($reservation['requested_at'])) ?></div>
            <div><strong>Pharmacy</strong><br><?= htmlspecialchars($reservation['pharmacy_name']) ?></div>
        </div>
        <div class="info-grid">
            <div><strong>Patient:</strong> <?= htmlspecialchars($u['full_name'] ?? '') ?></div>
            <div><strong>Mobile:</strong> <?= htmlspecialchars($u['phone'] ?? '') ?></div>
            <div><strong>Email:</strong> <?= htmlspecialchars($u['email'] ?? '') ?></div>
            <div><strong>Address:</strong> <?= htmlspecialchars($u['address'] ?? '') ?></div>
            <div><strong>Delivery Status:</strong> Reserve</div>
            <div><strong>Reservation Status:</strong> <?= htmlspecialchars($reservation['status']) ?></div>
            <div><strong>Accepted At:</strong> <?= $reservation['accepted_at'] ? date('d/m/Y h:i A', strtotime($reservation['accepted_at'])) : '-' ?></div>
            <div><strong>Pickup Deadline:</strong> <?= $reservation['pickup_deadline'] ? date('d/m/Y h:i A', strtotime($reservation['pickup_deadline'])) : '-' ?></div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th class="inv-qty" style="text-align:center">Quantity</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invMeds as $i => $medName): ?>
                    <tr>
                        <td><?= htmlspecialchars(trim($medName)) ?></td>
                        <td class="inv-qty"><?= htmlspecialchars($invQtys[$i] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (!empty($reservation['prescription_number'])): ?>
            <div class="document">
                <strong>Prescription Document</strong>
                <p style="margin:6px 0">Number: <?= htmlspecialchars($reservation['prescription_number']) ?> · Type: <?= htmlspecialchars($reservation['file_type']) ?> · Status: <?= htmlspecialchars($reservation['rx_status']) ?></p>
                <a href="../<?= htmlspecialchars($reservation['file_path']) ?>" target="_blank">View submitted prescription</a>
                <?php if (!empty($reservation['pharmacist_note'])): ?><p style="margin:8px 0 0"><strong>Pharmacist Note:</strong> <?= htmlspecialchars($reservation['pharmacist_note']) ?></p><?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="print"><button onclick="window.print()">Print Invoice</button></div>
    </div>
<?php else: ?>
    <div class="invoice">
        <h1>MediVault Invoice</h1>
        <div style="text-align:center;color:#666;margin-bottom:18px">Invoice for <?= htmlspecialchars($order['order_number']) ?></div>
        <div class="meta">
            <div><strong>Order Date &amp; Time</strong><br><?= date('d/m/Y h:i A', strtotime($order['placed_at'])) ?></div>
            <div><strong>Pharmacy</strong><br><?= htmlspecialchars($order['pharmacy_name']) ?></div>
        </div>
        <div class="info-grid">
            <div><strong>Patient:</strong> <?= htmlspecialchars($u['full_name'] ?? '') ?></div>
            <div><strong>Mobile:</strong> <?= htmlspecialchars($u['phone'] ?? '') ?></div>
            <div><strong>Email:</strong> <?= htmlspecialchars($u['email'] ?? '') ?></div>
            <div><strong>Address:</strong> <?= htmlspecialchars($order['delivery_address'] ?: ($u['address'] ?? '')) ?></div>
            <div><strong>Delivery:</strong> <?= htmlspecialchars($order['fulfillment_type'] === 'HOME_DELIVERY' ? 'Home Delivery' : 'Reserve') ?></div>
            <div><strong>Payment:</strong> <?= htmlspecialchars($payment['payment_method'] ?? '') ?></div>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Unit Price</th>
                    <th style="text-align:center">Quantity</th>
                    <th>Subtotal</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($order['items'] as $i): ?>
                    <tr>
                        <td><?= htmlspecialchars(trim($i['medicine_name'] . ' ' . ($i['strength'] ?? ''))) ?></td>
                        <td><?= number_format((float)$i['unit_price'], 2) ?> tk.</td>
                        <td class="inv-qty"><?= $i['quantity'] ?></td>
                        <td><?= number_format((float)$i['line_total'], 2) ?> tk.</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="totals">
            <div><span>Subtotal</span><span><?= number_format((float)$order['subtotal'], 2) ?> tk.</span></div>
            <div><span>Delivery Charge</span><span><?= number_format((float)$order['delivery_fee'], 2) ?> tk.</span></div>
            <div class="total"><span>Total</span><span><?= number_format((float)$order['total_amount'], 2) ?> tk.</span></div>
        </div>
        <div style="margin-top:20px;border-top:1px solid #ddd;padding-top:15px">
            <strong>Payment Information</strong>
            <p style="margin:7px 0">Method: <?= htmlspecialchars($payment['payment_method'] ?? '-') ?><?= !empty($payment['payment_provider']) ? ' · ' . htmlspecialchars($payment['payment_provider']) : '' ?></p>
            <p style="margin:7px 0">Account/Card: <?= htmlspecialchars($payment['payment_account'] ?? '-') ?></p>
            <p style="margin:7px 0">Payment Status: <?= htmlspecialchars($payment['payment_status'] ?? '-') ?></p>
            <p style="margin:7px 0">Transaction Reference: <?= htmlspecialchars($payment['transaction_reference'] ?? '-') ?></p>
            <?php if (!empty($payment['paid_at'])): ?><p style="margin:7px 0">Paid At: <?= date('d/m/Y h:i A', strtotime($payment['paid_at'])) ?></p><?php endif; ?>
        </div>
        <div class="print"><button onclick="window.print()">Print Invoice</button></div>
    </div>
<?php endif; ?>
<?php require_once __DIR__ . '/../common/footer.php'; ?>