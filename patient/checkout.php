<?php
session_start();
require_once __DIR__ . '/../database/queries.php';
if (!isset($_SESSION['user_id'])) {
  header('Location: ../login/login.php');
  exit;
}
ensure_checkout_schema($conn);
$userId = (int) $_SESSION['user_id'];
$allItems = get_cart($conn, $userId);

/* Restore selection: POST > GET > session */
$selectedItemIds = [];
if (!empty($_POST['selected_items']))
  $selectedItemIds = array_map('intval', (array) $_POST['selected_items']);
elseif (!empty($_GET['selected_items']))
  $selectedItemIds = array_map('intval', (array) $_GET['selected_items']);
elseif (!empty($_SESSION['checkout_selected_ids'])) {
  $selectedItemIds = array_map('intval', (array) $_SESSION['checkout_selected_ids']);
  unset($_SESSION['checkout_selected_ids']);
}
$selectedItemIds = array_values(array_unique(array_filter($selectedItemIds)));
$selectedMap = array_fill_keys($selectedItemIds, true);

$items = $selectedItemIds
  ? array_values(array_filter($allItems, fn($item) => isset($selectedMap[(int) $item['cart_item_id']])))
  : $allItems;

$userStmt = $conn->prepare("SELECT full_name,email,phone,address FROM users WHERE user_id=? LIMIT 1");
$userStmt->bind_param('i', $userId);
$userStmt->execute();
$customer = $userStmt->get_result()->fetch_assoc() ?: ['full_name' => '', 'email' => '', 'phone' => '', 'address' => ''];
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
  $billing = ['name' => trim($_POST['name'] ?? ''), 'mobile' => trim($_POST['mobile'] ?? ''), 'email' => trim($_POST['email'] ?? ''), 'address' => trim($_POST['address'] ?? '')];
  $deliveryStatus = $_POST['delivery_status'] ?? '';
  $paymentMethod = $_POST['payment_method'] ?? '';
  $provider = $_POST['payment_provider'] ?? null;
  $account = $_POST['payment_account'] ?? null;
  $reservationDeadline = null;
  if ($deliveryStatus === 'RESERVE') {
    $date = trim($_POST['reserve_date'] ?? '');
    $time = trim($_POST['reserve_time'] ?? '');
    if ($date !== '' && $time !== '')
      $reservationDeadline = $date . ' ' . $time . ':00';
  }
  $reference = null;
  if ($paymentMethod === 'ONLINE_PAYMENT')
    $reference = generate_unique_txn_reference();
  $result = create_order_from_cart($conn, $userId, $billing, $deliveryStatus, $paymentMethod, $provider, $account, $reference, $reservationDeadline, $selectedItemIds);
  if ($result['ok']) {
    $_SESSION['checkout_success_popup'] = $result;
    header('Location: checkout.php?success=1');
    exit;
  }
  $error = $result['message'];
}
?>
<?php require_once __DIR__ . '/../common/header.php'; ?>
<style>
  .checkout-page {
    width: 100%
  }

  .checkout-page .checkout-grid {
    display: grid;
    grid-template-columns: 320px minmax(520px, 1fr);
    gap: 28px;
    align-items: start;
    max-width: 1080px;
    margin: 0 auto
  }

  .checkout-page .billing h2,
  .checkout-page .section-title {
    font-size: 23px;
    margin: 0 0 10px;
    font-weight: 700;
    color: #111
  }

  .checkout-page .billing .line {
    height: 1px;
    background: #777;
    margin-bottom: 9px
  }

  .checkout-page .billing input,
  .checkout-page .billing textarea {
    width: 100%;
    box-sizing: border-box;
    border: 0;
    background: #f1f1f1;
    padding: 11px 16px;
    margin-bottom: 9px;
    font-size: 15px;
    font-family: inherit;
    color: #333;
    outline: none;
    border-radius: 8px
  }

  .checkout-page .billing textarea {
    min-height: 48px;
    resize: vertical
  }

  .checkout-page .field-block {
    margin-top: 38px
  }

  .checkout-page .select-wrap {
    position: relative;
    width: 220px;
    min-width: 220px
  }

  .checkout-page .custom-select-trigger {
    width: 100%;
    height: 42px;
    border: 0;
    background: #fff;
    padding: 0 38px 0 14px;
    font-size: 15px;
    font-family: inherit;
    box-shadow: 0 0 0 1px #e2e8f0 inset;
    cursor: pointer;
    text-align: left;
    position: relative;
    border-radius: 10px
  }

  .checkout-page .custom-select-trigger .custom-arrow {
    position: absolute;
    right: 14px;
    top: 16px;
    width: 0;
    height: 0;
    border-left: 5px solid transparent;
    border-right: 5px solid transparent;
    border-top: 7px solid #222
  }

  .checkout-page .custom-menu {
    display: none;
    position: absolute;
    left: 0;
    top: calc(100% + 4px);
    width: 100%;
    background: #fff;
    border: 1px solid #d8e3e1;
    border-radius: 12px;
    box-shadow: 0 10px 28px rgba(15, 118, 110, .16);
    padding: 7px;
    z-index: 3000
  }

  .checkout-page .custom-menu.open {
    display: block
  }

  .checkout-page .custom-option {
    width: 100%;
    display: flex;
    align-items: center;
    gap: 10px;
    border: 0;
    background: #fff;
    padding: 10px 11px;
    border-radius: 9px;
    font: 600 14px Arial, sans-serif;
    color: #1f2937;
    cursor: pointer;
    text-align: left
  }

  .checkout-page .custom-option:hover {
    background: #eaf6f4;
    color: #078f86
  }

  .checkout-page .custom-option .option-icon {
    width: 26px;
    height: 26px;
    border-radius: 7px;
    display: grid;
    place-items: center;
    background: #e5f3f1;
    color: #078f86;
    font-size: 14px;
    font-weight: 800
  }

  .checkout-page .custom-option.active {
    background: #f0f9f7;
    color: #078f86
  }

  .checkout-page .custom-option.active .option-icon {
    background: #078f86;
    color: #fff
  }

  .checkout-page .place-btn:disabled {
    opacity: .55;
    cursor: not-allowed
  }

  .checkout-page .checkout-box {
    border: 1px solid #e2e8f0;
    padding: 14px 16px 18px;
    min-height: 420px;
    background: #fafafa;
    border-radius: 14px
  }

  .checkout-page .product-head,
  .checkout-page .product-row {
    display: grid;
    grid-template-columns: minmax(220px, 1fr) 120px 85px 120px;
    gap: 14px;
    align-items: center
  }

  .checkout-page .product-head {
    font-size: 15px;
    font-weight: 700;
    border-bottom: 1px solid #ccc;
    padding: 0 10px 8px;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: .4px
  }

  .checkout-page .product-row {
    font-size: 15px;
    padding: 12px 10px;
    border-bottom: 1px solid #e5e5e5
  }

  .checkout-page .product-name {
    line-height: 1.35
  }

  .checkout-page .product-pharmacy {
    font-size: 12px;
    color: #6b7280;
    margin-top: 3px
  }

  .checkout-page .summary {
    margin-top: 28px;
    border-top: 1px solid #ccc;
    padding: 14px 10px 0;
    font-size: 16px;
    font-weight: 700
  }

  .checkout-page .summary-line {
    display: flex;
    justify-content: space-between;
    margin: 7px 0
  }

  .checkout-page .delivery-reason {
    font-size: 12px;
    line-height: 1.45;
    color: #66736f;
    background: #eef8f6;
    border: 1px solid #d7ece8;
    border-radius: 9px;
    padding: 8px 10px;
    margin: 5px 0 10px
  }

  .checkout-page .summary-line.total {
    font-size: 20px;
    margin-top: 12px
  }

  .checkout-page .place-wrap {
    text-align: center;
    margin-top: 14px
  }

  .checkout-page .place-btn {
    border: 0;
    background: #07988f;
    color: #fff;
    font-weight: 700;
    font-size: 18px;
    padding: 12px 28px;
    border-radius: 13px;
    cursor: pointer
  }

  .checkout-page .place-btn:hover:not(:disabled) {
    background: #087f78
  }

  .checkout-page .error {
    max-width: 1080px;
    margin: 0 auto 12px;
    background: #fee2e2;
    color: #991b1b;
    padding: 10px 14px;
    border-radius: 8px;
    font-weight: 600
  }

  .checkout-page .conditional {
    display: none;
    margin-top: 12px
  }

  .checkout-page .conditional.show {
    display: block
  }

  .checkout-page .conditional label {
    display: block;
    font-size: 14px;
    font-weight: 700;
    margin: 0 0 5px
  }

  .checkout-page .inline-fields {
    display: flex;
    gap: 10px;
    flex-wrap: wrap
  }

  .checkout-page .inline-fields input,
  .checkout-page .inline-fields select {
    border: 1px solid #ddd;
    background: #fff;
    padding: 8px 9px;
    font-family: inherit;
    font-size: 14px;
    border-radius: 8px
  }

  .checkout-page .mv-patient-time {
    display: flex;
    align-items: center;
    gap: 5px;
    min-height: 34px
  }

  .checkout-page .mv-patient-time select {
    border: 1px solid #ddd;
    background: #fff;
    padding: 8px 7px;
    font-family: inherit;
    font-size: 14px;
    cursor: pointer;
    border-radius: 8px
  }

  .checkout-page .mv-time-ampm {
    border: 1px solid #ddd;
    background: #fff;
    border-radius: 8px;
    padding: 8px 9px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer
  }

  .checkout-page .mv-time-ampm.active {
    background: #078f86;
    color: #fff;
    border-color: #078f86
  }

  .chosen-pay {
    display: none;
    margin-top: 12px;
    padding: 14px 16px;
    border-radius: 12px;
    background: linear-gradient(135deg, #f0faf8, #e8f5f2);
    border: 1px solid #cfe8e4;
    align-items: center;
    gap: 12px;
    font-size: 14px
  }

  .chosen-pay.show {
    display: flex
  }

  .chosen-pay .cp-icon {
    width: 44px;
    height: 44px;
    border-radius: 11px;
    background: #078f86;
    display: grid;
    place-items: center;
    font-weight: 800;
    color: #fff;
    font-size: 14px;
    box-shadow: 0 2px 6px rgba(7, 143, 134, .15);
    flex: 0 0 auto
  }

  .chosen-pay .cp-info {
    flex: 1;
    line-height: 1.4;
    min-width: 0
  }

  .chosen-pay .cp-info strong {
    display: block;
    font-size: 14px;
    color: #0f172a;
    font-weight: 800
  }

  .chosen-pay .cp-info small {
    font-size: 12px;
    color: #64748b;
    word-break: break-all;
    display: block;
    margin-top: 2px
  }

  .chosen-pay .cp-change {
    border: 0;
    background: #fff;
    color: #078f86;
    font-weight: 800;
    font-size: 12px;
    padding: 8px 16px;
    border-radius: 16px;
    cursor: pointer;
    box-shadow: 0 1px 3px rgba(0, 0, 0, .08);
    flex: 0 0 auto
  }

  .chosen-pay .cp-change:hover {
    background: #e0f2ef
  }

  /* Verified = locked */
  .chosen-pay.verified {
    background: linear-gradient(135deg, #dcfce7, #bbf7d0);
    border-color: #86efac;
    cursor: default
  }

  .chosen-pay.verified .cp-icon {
    background: #16a34a;
    box-shadow: 0 4px 12px rgba(22, 163, 74, .30)
  }

  .chosen-pay.verified .cp-info strong {
    color: #166534
  }

  .chosen-pay.verified .cp-info small {
    color: #15803d
  }

  .pg-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, .5);
    backdrop-filter: blur(3px);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    z-index: 100000
  }

  .pg-overlay.show {
    display: flex
  }

  .pg-modal {
    position: relative;
    width: min(760px, 100%);
    max-height: calc(100vh - 40px);
    overflow: auto;
    background: #fff;
    border-radius: 22px;
    padding: 28px 30px 26px;
    box-shadow: 0 24px 60px rgba(15, 23, 42, .35)
  }

  .pg-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 22px
  }

  .pg-head h2 {
    margin: 0 0 4px;
    font-size: 22px;
    font-weight: 800;
    color: #0f172a
  }

  .pg-head p {
    margin: 0;
    color: #64748b;
    font-size: 13px
  }

  .pg-close {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    border: 0;
    background: #f1f5f9;
    color: #334155;
    font-size: 22px;
    line-height: 1;
    cursor: pointer;
    flex: 0 0 auto
  }

  .pg-close:hover {
    background: #e2e8f0
  }

  .pg-steps {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 22px
  }

  .pg-steps .pg-step {
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    font-weight: 700;
    color: #94a3b8
  }

  .pg-steps .pg-step .dot {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: #e2e8f0;
    color: #64748b;
    display: grid;
    place-items: center;
    font-size: 12px;
    font-weight: 800
  }

  .pg-steps .pg-step.active {
    color: #078f86
  }

  .pg-steps .pg-step.active .dot {
    background: #078f86;
    color: #fff
  }

  .pg-steps .pg-line {
    flex: 1;
    height: 2px;
    background: #e2e8f0;
    border-radius: 2px
  }

  .pg-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 10px
  }

  .pg-tile {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 18px 12px;
    background: #fff;
    border: 1.5px solid #e2e8f0;
    border-radius: 16px;
    cursor: pointer;
    transition: all .15s ease;
    min-height: 110px;
    text-align: center
  }

  .pg-tile:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 26px rgba(15, 23, 42, .12);
    border-color: #a7d4ce
  }

  .pg-tile.active {
    border-color: #078f86;
    box-shadow: 0 0 0 2px rgba(7, 143, 134, .25), 0 12px 26px rgba(7, 143, 134, .18)
  }

  .pg-tile .pg-logo {
    width: 52px;
    height: 52px;
    border-radius: 14px;
    display: grid;
    place-items: center;
    color: #fff;
    font-weight: 800;
    font-size: 13px;
    padding: 0 6px
  }

  .pg-tile .pg-name {
    font-size: 14px;
    font-weight: 700;
    color: #0f172a
  }

  .pg-tile .pg-tag {
    font-size: 11px;
    color: #94a3b8
  }

  .pg-tile.active::after {
    content: "✓";
    position: absolute;
    top: 8px;
    right: 10px;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: #078f86;
    color: #fff;
    display: grid;
    place-items: center;
    font-size: 13px;
    font-weight: 800
  }

  .bkash-bg {
    background: linear-gradient(135deg, #e2136e, #b10f57)
  }

  .nagad-bg {
    background: linear-gradient(135deg, #ec1c24, #b3161c)
  }

  .rocket-bg {
    background: linear-gradient(135deg, #8a2be2, #5b1a9b)
  }

  .upay-bg {
    background: linear-gradient(135deg, #f9a825, #e07b00)
  }

  .card-bg {
    background: linear-gradient(135deg, #078f86, #0e7490)
  }

  .bank-bg {
    background: linear-gradient(135deg, #2563eb, #1e40af)
  }

  .pg-form {
    display: none;
    margin-top: 6px;
    padding-top: 20px;
    border-top: 1px dashed #e2e8f0
  }

  .pg-form.show {
    display: block
  }

  .pg-form-title {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px
  }

  .pg-form-title .pg-logo {
    width: 44px;
    height: 44px;
    border-radius: 11px;
    font-size: 11px;
    padding: 0 5px
  }

  .pg-form-title h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 800;
    color: #0f172a
  }

  .pg-form-title small {
    display: block;
    font-size: 12px;
    color: #64748b;
    font-weight: 500
  }

  .pg-field {
    margin-bottom: 12px
  }

  .pg-field label {
    display: block;
    font-size: 12px;
    font-weight: 700;
    color: #475569;
    margin-bottom: 5px;
    text-transform: uppercase
  }

  .pg-field input,
  .pg-field select {
    width: 100%;
    box-sizing: border-box;
    padding: 11px 14px;
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    font-size: 15px;
    font-family: inherit;
    outline: none;
    background: #fff
  }

  .pg-field input:focus,
  .pg-field select:focus {
    border-color: #078f86;
    box-shadow: 0 0 0 3px rgba(7, 143, 134, .12)
  }

  .pg-field-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px
  }

  .pg-amount {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(135deg, #f0faf8, #e8f5f2);
    border: 1px solid #cfe8e4;
    padding: 12px 16px;
    border-radius: 12px;
    margin-bottom: 18px
  }

  .pg-amount span {
    font-size: 13px;
    color: #475569;
    font-weight: 600
  }

  .pg-amount strong {
    font-size: 20px;
    color: #078f86;
    font-weight: 800
  }

  .pg-submit {
    width: 100%;
    border: 0;
    background: linear-gradient(135deg, #078f86, #0e7490);
    color: #fff;
    font-size: 15px;
    font-weight: 800;
    padding: 13px;
    border-radius: 12px;
    cursor: pointer;
    box-shadow: 0 8px 20px rgba(7, 143, 134, .28)
  }

  .pg-submit:hover {
    transform: translateY(-1px)
  }

  .pg-note {
    margin-top: 12px;
    font-size: 11px;
    color: #94a3b8;
    text-align: center
  }

  .pg-processing {
    display: none;
    text-align: center;
    padding: 40px 10px
  }

  .pg-processing.show {
    display: block
  }

  .pg-spinner {
    width: 60px;
    height: 60px;
    border: 5px solid #e2e8f0;
    border-top-color: #078f86;
    border-radius: 50%;
    margin: 0 auto 18px;
    animation: pgSpin 1s linear infinite
  }

  @keyframes pgSpin {
    to {
      transform: rotate(360deg)
    }
  }

  .pg-processing h3 {
    margin: 0 0 6px;
    font-size: 18px;
    color: #0f172a;
    font-weight: 800
  }

  .pg-processing p {
    margin: 0;
    color: #64748b;
    font-size: 13px
  }

  .pg-success {
    display: none;
    text-align: center;
    padding: 26px 10px
  }

  .pg-success.show {
    display: block
  }

  .pg-success .check {
    width: 70px;
    height: 70px;
    border-radius: 50%;
    background: linear-gradient(135deg, #078f86, #0e7490);
    color: #fff;
    display: grid;
    place-items: center;
    font-size: 36px;
    margin: 0 auto 16px;
    box-shadow: 0 10px 24px rgba(7, 143, 134, .35)
  }

  .pg-success h3 {
    margin: 0 0 8px;
    font-size: 20px;
    font-weight: 800;
    color: #0f172a
  }

  .pg-success p {
    margin: 0 0 6px;
    color: #475569;
    font-size: 14px
  }

  .pg-success .txn-ref {
    display: inline-block;
    margin-top: 10px;
    background: #f0faf8;
    border: 1px dashed #a7d4ce;
    color: #078f86;
    font-weight: 800;
    font-size: 13px;
    padding: 8px 16px;
    border-radius: 10px
  }

  @media(max-width:850px) {
    .checkout-page .checkout-grid {
      grid-template-columns: 1fr
    }

    .checkout-page .billing {
      max-width: 480px;
      margin: 0 auto
    }

    .pg-grid {
      grid-template-columns: repeat(2, 1fr)
    }

    .pg-field-row {
      grid-template-columns: 1fr
    }
  }
</style>
<div class="checkout-page">

  <?php if (isset($_GET['success']) && !empty($_SESSION['checkout_success_popup'])):
    $done = $_SESSION['checkout_success_popup'];
    unset($_SESSION['checkout_success_popup']); ?>
    <div class="modal-overlay" style="display:flex;">
      <div class="modal">
        <div class="check">✓</div>
        <h2>Order Successfully Done</h2>
        <p>
          <?= !empty($done['is_reservation']) ? 'Your reservation request has been sent to the selected pharmacies.' : 'Your order has been placed successfully.' ?>
        </p>
        <p>Total: <strong><?= number_format((float) ($done['total'] ?? 0), 2) ?> tk.</strong></p>
        <p style="font-size:13px;color:#666">Redirecting to Order History in <strong id="checkoutRedirectCount">5</strong>
          seconds.</p>
      </div>
    </div>
    <script>(function () { let n = 5, c = document.getElementById('checkoutRedirectCount'); let t = setInterval(function () { n--; if (c) c.textContent = n; if (n <= 0) { clearInterval(t); window.location.href = 'orders.php'; } }, 1000); })();</script>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <?php if (!$items): ?>
    <div class="empty">Please add at least one item to your cart before checkout.</div>
  <?php else: ?>
    <form method="post" id="checkoutForm">
      <?php foreach ($selectedItemIds as $id): ?><input type="hidden" name="selected_items[]"
          value="<?= $id ?>"><?php endforeach; ?>
      <div class="checkout-grid">
        <section class="billing">
          <h2>Billing Details</h2>
          <div class="line"></div>
          <input name="name" value="<?= htmlspecialchars($_POST['name'] ?? $customer['full_name']) ?>" placeholder="Name"
            required>
          <input name="mobile" value="<?= htmlspecialchars($_POST['mobile'] ?? $customer['phone']) ?>"
            placeholder="Mobile Number" required>
          <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? $customer['email']) ?>"
            placeholder="Email" required>
          <textarea name="address" placeholder="Address"
            required><?= htmlspecialchars($_POST['address'] ?? $customer['address']) ?></textarea>

          <div class="field-block">
            <div class="section-title">Delivery Status</div>
            <div class="select-wrap custom-select" data-name="delivery_status">
              <input type="hidden" name="delivery_status" id="deliveryStatus"
                value="<?= htmlspecialchars($_POST['delivery_status'] ?? 'HOME_DELIVERY') ?>" required>
              <button type="button" class="custom-select-trigger"><span
                  class="selected-label"><?= ($_POST['delivery_status'] ?? 'HOME_DELIVERY') === 'RESERVE' ? 'Reserve' : 'Home Delivery' ?></span><span
                  class="custom-arrow"></span></button>
              <div class="custom-menu">
                <button type="button" class="custom-option" data-value="RESERVE"><span
                    class="option-icon">◷</span><span>Reserve (Pickup later)</span></button>
                <button type="button" class="custom-option active" data-value="HOME_DELIVERY"><span
                    class="option-icon">⌂</span><span>Home Delivery</span></button>
              </div>
            </div>

            <div class="conditional" id="reserveFields">
              <div class="inline-fields">
                <div><label>Date</label><input type="date" name="reserve_date"
                    value="<?= htmlspecialchars($_POST['reserve_date'] ?? '') ?>"></div>
                <div><label>Time</label>
                  <div class="mv-patient-time" id="patientReserveTime">
                    <input type="hidden" name="reserve_time" value="<?= htmlspecialchars($_POST['reserve_time'] ?? '') ?>">
                    <select class="mv-time-hour"><?php for ($h = 1; $h <= 12; $h++): ?>
                        <option value="<?= $h ?>"><?= str_pad((string) $h, 2, '0', STR_PAD_LEFT) ?></option><?php endfor; ?>
                    </select>
                    <span>:</span>
                    <select class="mv-time-minute"><?php for ($m = 0; $m < 60; $m += 5): ?>
                        <option value="<?= $m ?>"><?= str_pad((string) $m, 2, '0', STR_PAD_LEFT) ?></option><?php endfor; ?>
                    </select>
                    <button type="button" class="mv-time-ampm" data-value="AM">AM</button>
                    <button type="button" class="mv-time-ampm" data-value="PM">PM</button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="field-block" style="margin-top:25px">
            <div class="section-title">Payment Status</div>
            <div class="select-wrap custom-select" data-name="payment_method" id="paymentMethodWrap">
              <input type="hidden" name="payment_method" id="paymentMethod"
                value="<?= htmlspecialchars($_POST['payment_method'] ?? '') ?>" required>
              <button type="button" class="custom-select-trigger"><span
                  class="selected-label"><?= ($_POST['payment_method'] ?? '') === 'CASH' ? 'Cash on Delivery' : (($_POST['payment_method'] ?? '') === 'ONLINE_PAYMENT' ? 'Online Payment' : 'Select Payment') ?></span><span
                  class="custom-arrow"></span></button>
              <div class="custom-menu">
                <button type="button" class="custom-option" data-value="CASH"><span class="option-icon">৳</span><span>Cash
                    on Delivery</span></button>
                <button type="button" class="custom-option" data-value="ONLINE_PAYMENT"><span
                    class="option-icon">▣</span><span>Online Payment</span></button>
              </div>
            </div>

            <div class="chosen-pay" id="chosenPay">
              <div class="cp-icon" id="chosenPayIcon">▣</div>
              <div class="cp-info">
                <strong id="chosenPayName">—</strong>
                <small id="chosenPayAccount">—</small>
              </div>
              <button type="button" class="cp-change" id="chosenPayChange">Change</button>
            </div>

            <input type="hidden" name="payment_provider" id="paymentProvider"
              value="<?= htmlspecialchars($_POST['payment_provider'] ?? '') ?>">
            <input type="hidden" name="payment_account" id="paymentAccount"
              value="<?= htmlspecialchars($_POST['payment_account'] ?? '') ?>">

            <div class="conditional" id="onlinePaymentAction">
              <button type="button" id="openPaymentGateway" class="pg-submit" style="max-width:280px;">
                <span id="openPaymentGatewayLabel">Choose Payment Method</span>
              </button>
            </div>
          </div>
        </section>

        <section class="checkout-box">
          <div class="product-head">
            <div>Product</div>
            <div>Unit Price</div>
            <div>Quantity</div>
            <div>Subtotal</div>
          </div>
          <?php $subtotal = 0;
          foreach ($items as $item):
            $line = (float) $item['unit_price'] * (int) $item['quantity'];
            $subtotal += $line; ?>
            <div class="product-row">
              <div class="product-name"><?= htmlspecialchars($item['medicine_name'] . ' ' . ($item['strength'] ?? '')) ?>
                <div class="product-pharmacy"><?= htmlspecialchars($item['pharmacy_name'] ?? 'Pharmacy') ?></div>
              </div>
              <div><?= number_format((float) $item['unit_price'], 0) ?></div>
              <div><?= (int) $item['quantity'] ?></div>
              <div><?= number_format($line, 0) ?></div>
            </div>
          <?php endforeach; ?>
          <?php
          $checkoutPharmacies = [];
          foreach ($items as $checkoutItem) {
            $pid = (int) ($checkoutItem['pharmacy_id'] ?? 0);
            $pname = trim((string) ($checkoutItem['pharmacy_name'] ?? 'Pharmacy'));
            if ($pid > 0 && !isset($checkoutPharmacies[$pid]))
              $checkoutPharmacies[$pid] = $pname;
          }
          $pharmacyCount = count($checkoutPharmacies);
          $pharmacyNames = implode(', ', array_values($checkoutPharmacies));
          $deliveryFeeAmount = $pharmacyCount * 80;
          ?>
          <div class="summary">
            <div class="summary-line"><span>Subtotal</span><span id="subtotalValue"
                data-value="<?= htmlspecialchars((string) $subtotal) ?>"><?= number_format($subtotal, 0) ?></span></div>
            <div class="summary-line delivery-summary-line" id="deliveryFeeLine" data-fee="<?= $deliveryFeeAmount ?>"
              data-pharmacy-count="<?= $pharmacyCount ?>"><span>Delivery Charge</span><span
                id="deliveryFeeValue"><?= number_format($deliveryFeeAmount, 0) ?></span></div>
            <div id="deliveryReason" class="delivery-reason" data-names="<?= htmlspecialchars($pharmacyNames, ENT_QUOTES) ?>"
              data-count="<?= $pharmacyCount ?>"></div>
            <div class="summary-line total"><span>Total</span><span id="grandTotal">—</span></div>
          </div>
          <div class="place-wrap"><button class="place-btn" type="submit" name="place_order" id="placeOrderBtn"
              disabled>Place Order</button></div>
        </section>
      </div>
    </form>
  <?php endif; ?>

  <div class="pg-overlay" id="pgOverlay" aria-hidden="true">
    <div class="pg-modal" role="dialog" aria-modal="true">
      <div class="pg-head">
        <div>
          <h2>Choose Payment Method</h2>
          <p>Secure demo gateway. No real money is charged.</p>
        </div>
        <button type="button" class="pg-close" id="pgClose">&times;</button>
      </div>
      <div class="pg-steps" id="pgSteps">
        <div class="pg-step active" data-step="1"><span class="dot">1</span>Choose</div>
        <div class="pg-line"></div>
        <div class="pg-step" data-step="2"><span class="dot">2</span>Details</div>
        <div class="pg-line"></div>
        <div class="pg-step" data-step="3"><span class="dot">3</span>Confirm</div>
      </div>
      <div id="pgStageChoose">
        <div class="pg-grid">
          <div class="pg-tile" data-provider="BKASH" data-name="bKash" data-type="mobile">
            <div class="pg-logo bkash-bg">bKash</div>
            <div class="pg-name">bKash</div>
            <div class="pg-tag">Mobile Banking</div>
          </div>
          <div class="pg-tile" data-provider="NAGAD" data-name="Nagad" data-type="mobile">
            <div class="pg-logo nagad-bg">Nagad</div>
            <div class="pg-name">Nagad</div>
            <div class="pg-tag">Mobile Banking</div>
          </div>
          <div class="pg-tile" data-provider="ROCKET" data-name="Rocket" data-type="mobile">
            <div class="pg-logo rocket-bg">Rocket</div>
            <div class="pg-name">Rocket</div>
            <div class="pg-tag">Mobile Banking</div>
          </div>
          <div class="pg-tile" data-provider="UPAY" data-name="Upay" data-type="mobile">
            <div class="pg-logo upay-bg">Upay</div>
            <div class="pg-name">Upay</div>
            <div class="pg-tag">Mobile Banking</div>
          </div>
          <div class="pg-tile" data-provider="CARD" data-name="Card" data-type="card">
            <div class="pg-logo card-bg">CARD</div>
            <div class="pg-name">Debit / Credit Card</div>
            <div class="pg-tag">Visa · Mastercard</div>
          </div>
          <div class="pg-tile" data-provider="BANK" data-name="Bank Transfer" data-type="bank">
            <div class="pg-logo bank-bg">BANK</div>
            <div class="pg-name">Bank Transfer</div>
            <div class="pg-tag">Direct Transfer</div>
          </div>
        </div>
      </div>
      <div class="pg-form" id="pgStageForm">
        <div class="pg-form-title">
          <div class="pg-logo" id="pgFormLogo"></div>
          <div>
            <h3 id="pgFormTitle">bKash</h3><small id="pgFormSubtitle">Enter account details</small>
          </div>
        </div>
        <div class="pg-amount"><span>Total to pay</span><strong id="pgAmount">— tk.</strong></div>
        <div id="pgFormMobile" class="pg-form-inner">
          <div class="pg-field"><label>Mobile Number</label><input type="tel" id="pgMobileNumber"
              placeholder="01XXXXXXXXX" maxlength="11"></div>
          <div class="pg-field"><label>PIN</label><input type="password" id="pgMobilePin" placeholder="4-digit PIN"
              maxlength="6"></div>
        </div>
        <div id="pgFormCard" class="pg-form-inner" style="display:none;">
          <div class="pg-field"><label>Card Holder Name</label><input type="text" id="pgCardHolder"
              placeholder="Name on card"></div>
          <div class="pg-field"><label>Card Number</label><input type="text" id="pgCardNumber"
              placeholder="1234 5678 9012 3456" maxlength="23"></div>
          <div class="pg-field-row">
            <div class="pg-field"><label>Expiry</label><input type="text" id="pgCardExpiry" placeholder="MM/YY"
                maxlength="5"></div>
            <div class="pg-field"><label>CVV</label><input type="password" id="pgCardCvv" placeholder="•••"
                maxlength="4"></div>
          </div>
        </div>
        <div id="pgFormBank" class="pg-form-inner" style="display:none;">
          <div class="pg-field"><label>Bank</label><select id="pgBankName">
              <option value="">Select your bank</option>
              <option value="BRAC Bank">BRAC Bank</option>
              <option value="Dutch-Bangla Bank">Dutch-Bangla Bank</option>
              <option value="City Bank">City Bank</option>
              <option value="Eastern Bank">Eastern Bank</option>
              <option value="Islami Bank">Islami Bank</option>
              <option value="Standard Chartered">Standard Chartered</option>
              <option value="Other Bank">Other Bank</option>
            </select></div>
          <div class="pg-field"><label>Account Number</label><input type="text" id="pgBankAccount"
              placeholder="Your account number"></div>
        </div>
        <button type="button" class="pg-submit" id="pgSubmitPayment" style="margin-top:14px;">Confirm Payment</button>
        <div class="pg-note">🔒 Demo payment gateway — no real charge made.</div>
      </div>
      <div class="pg-processing" id="pgStageProcessing">
        <div class="pg-spinner"></div>
        <h3>Processing Payment…</h3>
        <p>Please wait a moment.</p>
      </div>
      <div class="pg-success" id="pgStageSuccess">
        <div class="check">✓</div>
        <h3>Payment Successful</h3>
        <p>Your payment has been confirmed.</p>
        <p id="pgSuccessAmount" style="font-weight:700;color:#078f86;">— tk.</p>
        <span class="txn-ref" id="pgSuccessRef">TXN-—</span>
        <div style="margin-top:22px;"><button type="button" class="pg-submit" id="pgSuccessDone"
            style="max-width:220px;margin:0 auto;display:block;">Continue</button></div>
      </div>
    </div>
  </div>

  <script>
    (function () {
      const delivery = document.getElementById('deliveryStatus');
      const reserve = document.getElementById('reserveFields');
      const pay = document.getElementById('paymentMethod');
      const onlineAction = document.getElementById('onlinePaymentAction');
      const provider = document.getElementById('paymentProvider');
      const account = document.getElementById('paymentAccount');
      const chosenPay = document.getElementById('chosenPay');
      const chosenPayIcon = document.getElementById('chosenPayIcon');
      const chosenPayName = document.getElementById('chosenPayName');
      const chosenPayAccount = document.getElementById('chosenPayAccount');
      const chosenPayChange = document.getElementById('chosenPayChange');
      const subEl = document.getElementById('subtotalValue');
      const totalEl = document.getElementById('grandTotal');
      const feeLine = document.getElementById('deliveryFeeLine');

      let paymentVerified = false;

      const dropdowns = [...document.querySelectorAll('.custom-select')];
      const labels = {
        delivery_status: { RESERVE: 'Reserve', HOME_DELIVERY: 'Home Delivery' },
        payment_method: { CASH: 'Cash on Delivery', ONLINE_PAYMENT: 'Online Payment' }
      };

      function syncCustom(dd) {
        const input = dd.querySelector('input[type=hidden]');
        const label = dd.querySelector('.selected-label');
        const menu = dd.querySelector('.custom-menu');
        if (!input || !label) return;
        const name = dd.dataset.name, value = input.value;
        label.textContent = (labels[name] && labels[name][value]) || 'Select';
        dd.querySelectorAll('.custom-option').forEach(o => o.classList.toggle('active', o.dataset.value === value));
        if (menu) menu.classList.remove('open');
      }

      dropdowns.forEach(dd => {
        const trigger = dd.querySelector('.custom-select-trigger');
        const menu = dd.querySelector('.custom-menu');
        const input = dd.querySelector('input[type=hidden]');
        trigger.addEventListener('click', function (e) {
          e.stopPropagation();
          if (dd.dataset.name === 'payment_method' && paymentVerified) return;
          document.querySelectorAll('.custom-menu.open').forEach(m => { if (m !== menu) m.classList.remove('open') });
          menu.classList.toggle('open');
        });
        dd.querySelectorAll('.custom-option').forEach(opt => opt.addEventListener('click', function (e) {
          e.stopPropagation();
          if (dd.dataset.name === 'payment_method' && paymentVerified) { menu.classList.remove('open'); return; }
          input.value = this.dataset.value;
          syncCustom(dd);
          input.dispatchEvent(new Event('change', { bubbles: true }));
        }));
        syncCustom(dd);
      });
      document.addEventListener('click', () => document.querySelectorAll('.custom-menu.open').forEach(m => m.classList.remove('open')));

      const reserveTimeBox = document.getElementById('patientReserveTime');
      if (reserveTimeBox) {
        const hidden = reserveTimeBox.querySelector('input[type=hidden]');
        const hour = reserveTimeBox.querySelector('.mv-time-hour');
        const minute = reserveTimeBox.querySelector('.mv-time-minute');
        const buttons = [...reserveTimeBox.querySelectorAll('.mv-time-ampm')];
        let meridian = 'AM';
        function pad(n) { return String(n).padStart(2, '0'); }
        function syncReserveTime() {
          let h = parseInt(hour.value || '1', 10) % 12;
          if (meridian === 'PM') h += 12;
          hidden.value = pad(h) + ':' + pad(parseInt(minute.value || '0', 10));
        }
        function loadReserveTime() {
          if (!hidden.value) { buttons[0]?.classList.add('active'); syncReserveTime(); return; }
          const parts = hidden.value.split(':');
          let h = parseInt(parts[0] || '0', 10), m = parseInt(parts[1] || '0', 10);
          meridian = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12;
          hour.value = String(h); minute.value = String(Math.floor(m / 5) * 5);
          buttons.forEach(b => b.classList.toggle('active', b.dataset.value === meridian));
          syncReserveTime();
        }
        buttons.forEach(btn => btn.addEventListener('click', () => {
          meridian = btn.dataset.value;
          buttons.forEach(b => b.classList.toggle('active', b === btn));
          syncReserveTime();
        }));
        hour.addEventListener('change', syncReserveTime);
        minute.addEventListener('change', syncReserveTime);
        loadReserveTime();
      }

      function toggleDelivery() {
        if (!delivery) return;
        reserve.classList.toggle('show', delivery.value === 'RESERVE');
        const home = delivery.value === 'HOME_DELIVERY';
        feeLine.style.display = home ? 'flex' : 'none';
        const fee = home ? parseFloat(feeLine.dataset.fee || 0) : 0;
        const count = parseInt(feeLine.dataset.pharmacyCount || '0', 10);
        const reason = document.getElementById('deliveryReason');
        if (reason) {
          if (home && count > 1) { reason.textContent = 'Home delivery from ' + count + ' pharmacies: ' + reason.dataset.names + '. Delivery charge = ' + count + ' × 80 tk.'; reason.style.display = 'block'; }
          else if (home && count === 1) { reason.textContent = 'Home delivery from: ' + reason.dataset.names + '. Delivery charge = 80 tk.'; reason.style.display = 'block'; }
          else { reason.textContent = ''; reason.style.display = 'none'; }
        }
        document.getElementById('deliveryFeeValue').textContent = fee.toLocaleString('en-US');
        totalEl.textContent = (parseFloat(subEl.dataset.value || 0) + fee).toLocaleString('en-US');
      }

      function updatePaymentUI() {
        if (!pay) return;
        const isOnline = pay.value === 'ONLINE_PAYMENT';
        onlineAction.classList.toggle('show', isOnline);
        if (!isOnline) {
          provider.value = ''; account.value = ''; paymentVerified = false;
        }
        refreshChosenPay();
        updatePlaceBtn();
      }

      const brandMap = {
        BKASH: { name: 'bKash', icon: 'bKash', cls: 'bkash-bg' },
        NAGAD: { name: 'Nagad', icon: 'Nagad', cls: 'nagad-bg' },
        ROCKET: { name: 'Rocket', icon: 'Rocket', cls: 'rocket-bg' },
        UPAY: { name: 'Upay', icon: 'Upay', cls: 'upay-bg' },
        CARD: { name: 'Card Payment', icon: 'CARD', cls: 'card-bg' },
        BANK: { name: 'Bank Transfer', icon: 'BANK', cls: 'bank-bg' }
      };

      function refreshChosenPay() {
        const p = provider.value, acc = account.value;
        if (pay.value === 'ONLINE_PAYMENT' && p && acc && paymentVerified) {
          const brand = brandMap[p] || { name: p, icon: '▣', cls: 'card-bg' };
          chosenPay.classList.add('show'); chosenPay.classList.add('verified');
          chosenPayName.textContent = brand.name + '  •  Payment Verified';
          chosenPayAccount.textContent = acc;
          chosenPayIcon.textContent = '✓';
          chosenPayIcon.className = 'cp-icon'; chosenPayIcon.style.background = '#16a34a';
          chosenPayChange.style.display = 'none';
          document.getElementById('openPaymentGateway').style.display = 'none';
          document.getElementById('paymentMethodWrap').style.pointerEvents = 'none';
        } else if (pay.value === 'ONLINE_PAYMENT' && p && acc) {
          const brand = brandMap[p] || { name: p, icon: '▣', cls: 'card-bg' };
          chosenPay.classList.add('show'); chosenPay.classList.remove('verified');
          chosenPayName.textContent = brand.name;
          chosenPayAccount.textContent = acc;
          chosenPayIcon.textContent = brand.icon;
          chosenPayIcon.className = 'cp-icon ' + brand.cls; chosenPayIcon.style.background = '';
          chosenPayChange.style.display = '';
          document.getElementById('openPaymentGateway').style.display = '';
          document.getElementById('paymentMethodWrap').style.pointerEvents = '';
        } else {
          chosenPay.classList.remove('show'); chosenPay.classList.remove('verified');
          chosenPayChange.style.display = '';
          document.getElementById('openPaymentGateway').style.display = '';
          document.getElementById('paymentMethodWrap').style.pointerEvents = '';
        }
      }
      chosenPayChange.addEventListener('click', () => openGateway());

      function updatePlaceBtn() {
        const place = document.getElementById('placeOrderBtn');
        if (!place) return;
        const isCash = pay.value === 'CASH';
        const isOnline = pay.value === 'ONLINE_PAYMENT';
        const onlineComplete = isOnline && provider.value && account.value && paymentVerified;
        place.disabled = !(isCash || onlineComplete);
      }

      delivery.addEventListener('change', toggleDelivery);
      pay.addEventListener('change', updatePaymentUI);
      provider.addEventListener('change', () => { refreshChosenPay(); updatePlaceBtn(); });
      account.addEventListener('change', () => { refreshChosenPay(); updatePlaceBtn(); });

      toggleDelivery();
      updatePaymentUI();

      /* -------- Payment modal -------- */
      const pgOverlay = document.getElementById('pgOverlay');
      const pgStageChoose = document.getElementById('pgStageChoose');
      const pgStageForm = document.getElementById('pgStageForm');
      const pgStageProcessing = document.getElementById('pgStageProcessing');
      const pgStageSuccess = document.getElementById('pgStageSuccess');
      const pgFormMobile = document.getElementById('pgFormMobile');
      const pgFormCard = document.getElementById('pgFormCard');
      const pgFormBank = document.getElementById('pgFormBank');
      const pgAmountEl = document.getElementById('pgAmount');
      const pgFormTitle = document.getElementById('pgFormTitle');
      const pgFormSubtitle = document.getElementById('pgFormSubtitle');
      const pgFormLogo = document.getElementById('pgFormLogo');
      const pgSteps = document.querySelectorAll('.pg-step');
      const pgTiles = document.querySelectorAll('.pg-tile');
      let selectedProvider = null;

      function currentTotal() {
        const sub = parseFloat(subEl.dataset.value || 0);
        const home = delivery.value === 'HOME_DELIVERY';
        const fee = home ? parseFloat(feeLine.dataset.fee || 0) : 0;
        return sub + fee;
      }
      function setStep(n) { pgSteps.forEach(s => s.classList.toggle('active', parseInt(s.dataset.step, 10) <= n)); }

      function openGateway() {
        if (paymentVerified) return;
        pgOverlay.classList.add('show');
        document.body.style.overflow = 'hidden';
        pgAmountEl.textContent = currentTotal().toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' tk.';
        pgStageChoose.style.display = '';
        pgStageForm.classList.remove('show');
        pgStageProcessing.classList.remove('show');
        pgStageSuccess.classList.remove('show');
        setStep(1);
        ['pgMobileNumber', 'pgMobilePin', 'pgCardHolder', 'pgCardNumber', 'pgCardExpiry', 'pgCardCvv', 'pgBankAccount'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
        const bankSel = document.getElementById('pgBankName'); if (bankSel) bankSel.value = '';
        selectedProvider = null;
        pgTiles.forEach(t => t.classList.remove('active'));
      }
      function closeGateway() { pgOverlay.classList.remove('show'); document.body.style.overflow = ''; }

      function showForm(tile) {
        selectedProvider = { code: tile.dataset.provider, name: tile.dataset.name, type: tile.dataset.type };
        pgTiles.forEach(t => t.classList.toggle('active', t === tile));
        pgStageChoose.style.display = 'none';
        pgStageForm.classList.add('show');
        setStep(2);
        const brand = brandMap[selectedProvider.code] || { icon: '▣', cls: 'card-bg' };
        pgFormTitle.textContent = selectedProvider.name;
        pgFormLogo.textContent = brand.icon;
        pgFormLogo.className = 'pg-logo ' + brand.cls;
        pgFormMobile.style.display = 'none'; pgFormCard.style.display = 'none'; pgFormBank.style.display = 'none';
        if (selectedProvider.type === 'mobile') { pgFormMobile.style.display = ''; pgFormSubtitle.textContent = 'Enter your ' + selectedProvider.name + ' account and PIN'; }
        else if (selectedProvider.type === 'card') { pgFormCard.style.display = ''; pgFormSubtitle.textContent = 'Enter your card information'; }
        else { pgFormBank.style.display = ''; pgFormSubtitle.textContent = 'Select your bank and account number'; }
      }

      function validateForm() {
        if (!selectedProvider) return false;
        if (selectedProvider.type === 'mobile') {
          const num = document.getElementById('pgMobileNumber').value.trim();
          const pin = document.getElementById('pgMobilePin').value.trim();
          if (!/^01\d{9}$/.test(num)) { alert('Enter a valid 11-digit mobile number.'); return false; }
          if (!/^\d{4,6}$/.test(pin)) { alert('Enter a valid PIN.'); return false; }
          return true;
        }
        if (selectedProvider.type === 'card') {
          const holder = document.getElementById('pgCardHolder').value.trim();
          const num = document.getElementById('pgCardNumber').value.replace(/\s/g, '');
          const exp = document.getElementById('pgCardExpiry').value.trim();
          const cvv = document.getElementById('pgCardCvv').value.trim();
          if (holder.length < 3) { alert('Enter the card holder name.'); return false; }
          if (!/^\d{13,19}$/.test(num)) { alert('Enter a valid card number.'); return false; }
          if (!/^(0[1-9]|1[0-2])\/\d{2}$/.test(exp)) { alert('Enter expiry as MM/YY.'); return false; }
          if (!/^\d{3,4}$/.test(cvv)) { alert('Enter a valid CVV.'); return false; }
          return true;
        }
        if (selectedProvider.type === 'bank') {
          const bank = document.getElementById('pgBankName').value;
          const acc = document.getElementById('pgBankAccount').value.trim();
          if (!bank) { alert('Select a bank.'); return false; }
          if (acc.length < 6) { alert('Enter a valid account number.'); return false; }
          return true;
        }
        return false;
      }

      function buildAccountLabel() {
        if (!selectedProvider) return '';
        if (selectedProvider.type === 'mobile') return document.getElementById('pgMobileNumber').value.trim();
        if (selectedProvider.type === 'card') { const n = document.getElementById('pgCardNumber').value.replace(/\s/g, ''); return '**** **** **** ' + n.slice(-4); }
        if (selectedProvider.type === 'bank') return document.getElementById('pgBankName').value + ' · ' + document.getElementById('pgBankAccount').value.trim();
        return '';
      }

      function finishPayment() {
        const now = new Date();
        const pad = n => String(n).padStart(2, '0');
        const ref = 'TXN-MV-' + now.getFullYear() + pad(now.getMonth() + 1) + pad(now.getDate()) + pad(now.getHours()) + pad(now.getMinutes()) + pad(now.getSeconds()) + '-' + Math.floor(1000 + Math.random() * 9000);
        document.getElementById('pgSuccessRef').textContent = ref;
        document.getElementById('pgSuccessAmount').textContent = currentTotal().toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' tk.';
        pgStageProcessing.classList.remove('show');
        pgStageSuccess.classList.add('show');
        setStep(3);
      }

      const cardNumInput = document.getElementById('pgCardNumber');
      cardNumInput.addEventListener('input', function () { let v = this.value.replace(/\D/g, '').slice(0, 19); this.value = v.replace(/(.{4})/g, '$1 ').trim(); });
      const cardExpInput = document.getElementById('pgCardExpiry');
      cardExpInput.addEventListener('input', function () { let v = this.value.replace(/\D/g, '').slice(0, 4); this.value = v.length >= 3 ? v.slice(0, 2) + '/' + v.slice(2) : v; });

      pgTiles.forEach(t => t.addEventListener('click', () => showForm(t)));
      document.getElementById('pgClose').addEventListener('click', closeGateway);
      pgOverlay.addEventListener('click', e => { if (e.target === pgOverlay) closeGateway(); });
      document.addEventListener('keydown', e => { if (e.key === 'Escape' && pgOverlay.classList.contains('show')) closeGateway(); });

      document.getElementById('pgSubmitPayment').addEventListener('click', function () {
        if (!validateForm()) return;
        provider.value = selectedProvider.code;
        account.value = buildAccountLabel();
        paymentVerified = true;
        provider.dispatchEvent(new Event('change', { bubbles: true }));
        pgStageForm.classList.remove('show');
        pgStageProcessing.classList.add('show');
        setStep(3);
        setTimeout(finishPayment, 1300);
      });

      document.getElementById('pgSuccessDone').addEventListener('click', function () {
        refreshChosenPay();
        updatePlaceBtn();
        closeGateway();
      });

      document.getElementById('openPaymentGateway').addEventListener('click', openGateway);
    })();

    (function () {
      const form = document.getElementById('checkoutForm');
      if (!form) return;
      form.addEventListener('submit', function (e) {
        const delivery = document.getElementById('deliveryStatus');
        const pay = document.getElementById('paymentMethod');
        const provider = document.getElementById('paymentProvider');
        const account = document.getElementById('paymentAccount');
        if (delivery.value === 'RESERVE') {
          const date = document.querySelector('[name=reserve_date]').value;
          const time = document.querySelector('[name=reserve_time]').value;
          if (!date || !time) { e.preventDefault(); alert('Please select reservation date and time.'); return; }
        }
        if (pay.value === 'ONLINE_PAYMENT' && (!provider.value || !account.value)) {
          e.preventDefault();
          alert('Please complete the payment details first.');
          return;
        }
      });
    })();
  </script>
</div>
<?php require_once __DIR__ . '/../common/footer.php'; ?>