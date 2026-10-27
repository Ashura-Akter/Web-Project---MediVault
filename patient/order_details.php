<?php
session_start();
require_once __DIR__ . '/../database/queries.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login/login.php');
    exit;
}
$order = get_order_details($conn, (int)$_SESSION['user_id'], (int)($_GET['id'] ?? 0));
if (!$order) {
    header('Location: orders.php');
    exit;
}

$statusLabel = strtoupper((string)$order['order_status']);
$statusColors = [
    'PROCESSING' => ['#fff3cd', '#7a5a00'],
    'CONFIRMED'  => ['#e0f2fe', '#0c4a6e'],
    'READY'      => ['#dcfce7', '#166534'],
    'DELIVERED'  => ['#d1fae5', '#065f46'],
    'COMPLETED'  => ['#d1fae5', '#065f46'],
    'CANCELLED'  => ['#fee2e2', '#991b1b'],
];
[$statusBg, $statusText] = $statusColors[$statusLabel] ?? ['#e5e7eb', '#374151'];
$paymentLabel = $order['payment_status'] ?? 'UNPAID';
?>
<?php require_once __DIR__ . '/../common/header.php'; ?>
<style>
    .od-page {
        max-width: 900px;
        margin: 0 auto;
        padding-bottom: 30px
    }

    /* Hero card */
    .od-hero {
        position: relative;
        background: linear-gradient(135deg, #078f86 0%, #0f766e 60%, #0e7490 100%);
        color: #fff;
        border-radius: 22px;
        padding: 30px 34px;
        margin-bottom: 22px;
        box-shadow: 0 14px 36px rgba(7, 143, 134, .26);
        overflow: hidden
    }

    .od-hero::before {
        content: "";
        position: absolute;
        right: -40px;
        top: -40px;
        width: 200px;
        height: 200px;
        background: radial-gradient(circle, rgba(255, 255, 255, .22), transparent 65%);
        border-radius: 50%
    }

    .od-hero::after {
        content: "";
        position: absolute;
        right: -70px;
        bottom: -90px;
        width: 240px;
        height: 240px;
        background: radial-gradient(circle, rgba(255, 255, 255, .14), transparent 65%);
        border-radius: 50%
    }

    .od-hero .od-status {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        border-radius: 14px;
        font-size: 13px;
        font-weight: 800;
        letter-spacing: .4px;
        margin-bottom: 14px;
        text-transform: uppercase;
        background: rgba(255, 255, 255, .22);
        position: relative;
        z-index: 1
    }

    .od-hero .od-status::before {
        content: "";
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: currentColor
    }

    .od-hero h1 {
        margin: 0 0 8px;
        font-size: 28px;
        font-weight: 800;
        letter-spacing: .3px;
        position: relative;
        z-index: 1
    }

    .od-hero .od-sub {
        opacity: .92;
        font-size: 14px;
        margin-bottom: 16px;
        position: relative;
        z-index: 1
    }

    .od-hero .od-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 18px;
        font-size: 13px;
        opacity: .96;
        position: relative;
        z-index: 1
    }

    .od-hero .od-meta span b {
        opacity: .85;
        font-weight: 600;
        margin-right: 5px
    }

    /* Cards */
    .od-card {
        background: #fff;
        border-radius: 18px;
        padding: 22px 26px;
        margin-bottom: 18px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, .05);
        border: 1px solid #eef2f3
    }

    .od-card h2 {
        margin: 0 0 18px;
        font-size: 17px;
        font-weight: 800;
        color: #0f172a;
        display: flex;
        align-items: center;
        gap: 10px;
        letter-spacing: .2px
    }

    .od-card h2::before {
        content: "";
        width: 5px;
        height: 22px;
        background: linear-gradient(180deg, #078f86, #0e7490);
        border-radius: 3px;
        display: inline-block
    }

    /* Items */
    .od-items {
        display: flex;
        flex-direction: column;
        gap: 10px
    }

    .od-item {
        display: grid;
        grid-template-columns: 1fr auto auto;
        gap: 16px;
        align-items: center;
        background: #f8fbfa;
        border: 1px solid #e2f0ee;
        border-radius: 12px;
        padding: 14px 18px;
        transition: box-shadow .15s ease, transform .15s ease
    }

    .od-item:hover {
        box-shadow: 0 6px 18px rgba(7, 143, 134, .14);
        transform: translateY(-1px)
    }

    .od-item .od-medname {
        font-size: 15px;
        font-weight: 700;
        color: #0f172a;
        line-height: 1.3
    }

    .od-item .od-unitprice {
        font-size: 12px;
        color: #8b939e;
        margin-top: 3px
    }

    .od-item .od-qty {
        font-size: 13px;
        background: #e6f7f4;
        color: #078f86;
        font-weight: 800;
        padding: 6px 14px;
        border-radius: 12px;
        white-space: nowrap
    }

    .od-item .od-price {
        font-size: 15px;
        font-weight: 800;
        color: #0f766e;
        min-width: 90px;
        text-align: right
    }

    /* Total row */
    .od-total {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-top: 2px dashed #cfe4e1;
        padding-top: 16px;
        margin-top: 20px;
        font-size: 15px;
        font-weight: 700;
        color: #0f172a
    }

    .od-total .od-total-value {
        font-size: 24px;
        font-weight: 800;
        color: #078f86
    }

    /* Info grid */
    .od-info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 14px
    }

    .od-info {
        background: #f8fbfa;
        border-radius: 12px;
        padding: 14px 18px;
        border: 1px solid #eef2f3
    }

    .od-info small {
        display: block;
        font-size: 12px;
        color: #7b838f;
        margin-bottom: 5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .4px
    }

    .od-info strong {
        font-size: 14px;
        color: #0f172a;
        font-weight: 700;
        word-break: break-word
    }

    /* Back button */
    .od-back {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: #fff;
        color: #078f86;
        font-weight: 700;
        font-size: 14px;
        text-decoration: none;
        padding: 11px 22px;
        border-radius: 14px;
        border: 1.5px solid #cfeae7;
        transition: background .15s ease, box-shadow .15s ease
    }

    .od-back:hover {
        background: #eaf6f4;
        box-shadow: 0 4px 12px rgba(7, 143, 134, .16)
    }

    @media(max-width:640px) {
        .od-hero {
            padding: 22px 22px
        }

        .od-hero h1 {
            font-size: 22px
        }

        .od-item {
            grid-template-columns: 1fr;
            gap: 8px;
            padding: 14px
        }

        .od-item .od-price {
            text-align: left
        }

        .od-card {
            padding: 18px 16px
        }
    }
</style>

<div class="od-page">
    <div class="od-hero">
        <span class="od-status" style="background:<?= $statusBg ?>;color:<?= $statusText ?>;opacity:1;">● <?= htmlspecialchars($order['order_status']) ?></span>
        <h1>Order <?= htmlspecialchars($order['order_number']) ?></h1>
        <div class="od-sub">Placed on <?= date('d M Y, h:i A', strtotime($order['placed_at'])) ?></div>
        <div class="od-meta">
            <span><b>Pharmacy:</b> <?= htmlspecialchars($order['pharmacy_name']) ?></span>
            <span><b>Fulfillment:</b> <?= htmlspecialchars($order['fulfillment_type'] === 'HOME_DELIVERY' ? 'Home Delivery' : 'Pickup') ?></span>
            <span><b>Payment:</b> <?= htmlspecialchars($paymentLabel) ?></span>
        </div>
    </div>

    <div class="od-card">
        <h2>Ordered Medicines</h2>
        <div class="od-items">
            <?php foreach ($order['items'] as $i): ?>
                <div class="od-item">
                    <div>
                        <div class="od-medname"><?= htmlspecialchars(trim($i['medicine_name'] . ' ' . ($i['strength'] ?? ''))) ?></div>
                        <div class="od-unitprice">Unit: <?= number_format((float)$i['unit_price'], 2) ?> tk.</div>
                    </div>
                    <div class="od-qty">Qty × <?= (int)$i['quantity'] ?></div>
                    <div class="od-price"><?= number_format((float)$i['line_total'], 2) ?> tk.</div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="od-total">
            <span>Total Amount</span>
            <span class="od-total-value"><?= number_format((float)$order['total_amount'], 2) ?> tk.</span>
        </div>
    </div>

    <div class="od-card">
        <h2>Order Information</h2>
        <div class="od-info-grid">
            <div class="od-info"><small>Order Number</small><strong><?= htmlspecialchars($order['order_number']) ?></strong></div>
            <div class="od-info"><small>Placed At</small><strong><?= date('d M Y, h:i A', strtotime($order['placed_at'])) ?></strong></div>
            <div class="od-info"><small>Order Status</small><strong><?= htmlspecialchars($order['order_status']) ?></strong></div>
            <div class="od-info"><small>Fulfillment</small><strong><?= htmlspecialchars($order['fulfillment_type'] === 'HOME_DELIVERY' ? 'Home Delivery' : 'Pickup') ?></strong></div>
            <div class="od-info"><small>Payment Status</small><strong><?= htmlspecialchars($paymentLabel) ?></strong></div>
            <div class="od-info"><small>Pharmacy</small><strong><?= htmlspecialchars($order['pharmacy_name']) ?></strong></div>
            <?php if (!empty($order['delivery_address'])): ?>
                <div class="od-info" style="grid-column:1/-1;"><small>Delivery Address</small><strong><?= htmlspecialchars($order['delivery_address']) ?></strong></div>
            <?php endif; ?>
        </div>
    </div>

    <div style="text-align:center;margin-top:12px;">
        <a class="od-back" href="orders.php">← Back to Order History</a>
    </div>
</div>
<?php require_once __DIR__ . '/../common/footer.php'; ?>