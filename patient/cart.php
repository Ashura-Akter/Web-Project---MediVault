<?php
session_start();

require_once __DIR__ . '/../database/queries.php';
ensure_checkout_schema($conn);

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login/login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];

// Shows whole numbers without decimals (35) and fractions with two decimals (2.60).
if (!function_exists('cart_money')) {
    function cart_money($x) {
        $x = round((float)$x, 2);
        return floor($x) == $x ? number_format($x, 0) : number_format($x, 2);
    }
}

// Available stock for a cart item (all pharmacies, unexpired batches). Used as the quantity limit.
$stockSql = "(SELECT COALESCE(SUM(GREATEST(ib.quantity_on_hand - ib.reserved_quantity, 0)), 0)
              FROM inventory_batches ib
              WHERE ib.medicine_id = ci.medicine_id AND ib.pharmacy_id = ci.pharmacy_id AND ib.expiry_date > CURDATE())";

// ---------- AJAX: change the quantity of a cart item ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_qty') {
    header('Content-Type: application/json; charset=utf-8');

    $itemId = (int)($_POST['cart_item_id'] ?? 0);
    $qty = (int)($_POST['quantity'] ?? 0);

    if ($itemId <= 0 || $qty < 1) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Please enter a valid quantity.']);
        exit;
    }

    try {
        // The item must belong to this patient's active cart.
        $st = $conn->prepare(
            "SELECT ci.cart_item_id, ci.quantity, ci.unit_price, $stockSql AS stock
             FROM cart_items ci
             JOIN carts c ON c.cart_id = ci.cart_id
             WHERE ci.cart_item_id = ? AND c.user_id = ? AND c.status = 'ACTIVE'
             LIMIT 1"
        );
        $st->bind_param('ii', $itemId, $userId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();

        if (!$row) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'This item is no longer in your cart.']);
            exit;
        }

        // Never allow more than the available stock (a smaller current quantity can always be kept).
        $maxQty = max((int)$row['stock'], (int)$row['quantity']);
        $limited = $qty > $maxQty;
        if ($limited) {
            $qty = $maxQty;
        }

        $up = $conn->prepare("UPDATE cart_items SET quantity = ? WHERE cart_item_id = ?");
        $up->bind_param('ii', $qty, $itemId);
        $up->execute();

        $tt = $conn->prepare(
            "SELECT COALESCE(SUM(ci.quantity * ci.unit_price), 0)
             FROM cart_items ci
             JOIN carts c ON c.cart_id = ci.cart_id
             WHERE c.user_id = ? AND c.status = 'ACTIVE'"
        );
        $tt->bind_param('i', $userId);
        $tt->execute();
        $cartTotal = (float)($tt->get_result()->fetch_row()[0] ?? 0);

        echo json_encode([
            'ok'         => true,
            'quantity'   => $qty,
            'max'        => $maxQty,
            'unit_price' => (float)$row['unit_price'],
            'line_total' => round($qty * (float)$row['unit_price'], 2),
            'cart_total' => round($cartTotal, 2),
            'limited'    => $limited,
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not update the quantity. Please try again.']);
    }
    exit;
}

// ---------- Cart items ----------
$st = $conn->prepare(
    "SELECT ci.cart_item_id, ci.medicine_id, ci.quantity, ci.unit_price,
            (ci.quantity * ci.unit_price) AS line_total,
            m.medicine_name, m.strength, m.is_restricted, p.pharmacy_name,
            $stockSql AS stock
     FROM carts c
     JOIN cart_items ci ON ci.cart_id = c.cart_id
     JOIN medicines m ON m.medicine_id = ci.medicine_id
     LEFT JOIN pharmacies p ON p.pharmacy_id = ci.pharmacy_id
     WHERE c.user_id = ? AND c.status = 'ACTIVE'
     ORDER BY ci.added_at, ci.cart_item_id"
);
$st->bind_param('i', $userId);
$st->execute();
$items = $st->get_result()->fetch_all(MYSQLI_ASSOC);

$total = 0;

foreach ($items as $item) {
    $total += (float)$item['line_total'];
}

$hasRestricted = false;

foreach ($items as $item) {
    if ((int)$item['is_restricted'] === 1) {
        $hasRestricted = true;
        break;
    }
}
?>

<?php require_once __DIR__ . '/../common/header.php'; ?>

<style>
    .cart-page h1 { font-size: 40px; margin-bottom: 22px; }
    .cart-page .empty { font-size: 22px; padding: 30px 0; }

    .cart-page .cart-table th { font-size: 16px; font-weight: 700; padding: 18px 22px; }
    .cart-page .cart-table td { font-size: 17px; padding: 16px 18px; vertical-align: middle; }
    .cart-page .restricted-cart-note { font-size: 15px; font-weight: 600; margin-top: 4px; }

    /* Quantity: - [ 1 ] + */
    .cart-page .qty-stepper { display: inline-flex; align-items: center; background: #fff; border: 1px solid #cbd5e1; border-radius: 12px; overflow: hidden; }
    .cart-page .qty-btn { width: 36px; height: 36px; border: 0; background: #f1f5f9; color: #0f766e; font-size: 21px; font-weight: 700; line-height: 1; cursor: pointer; padding: 0; }
    .cart-page .qty-btn:hover:not(:disabled) { background: #d5efeb; }
    .cart-page .qty-btn:disabled { opacity: .35; cursor: not-allowed; }
    .cart-page .qty-input { width: 52px; height: 36px; border: 0; outline: none; text-align: center; font-size: 17px; font-weight: 700; color: #111827; background: #fff; -moz-appearance: textfield; }
    .cart-page .qty-input::-webkit-outer-spin-button,
    .cart-page .qty-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

    .cart-page .cart-total-row td { font-size: 22px; font-weight: 800; background: #f1f5f9; }
    .cart-page .cart-total-row td:first-child { text-align: right; }

    .cart-page .cart-msg { font-size: 17px; font-weight: 600; padding: 12px 18px; border-radius: 12px; margin-bottom: 14px; background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
    .cart-page .cart-msg[hidden] { display: none; }

    .cart-page .checkout-btn { font-size: 21px; font-weight: 700; padding: 16px 38px; }
    .cart-page tbody tr:nth-child(odd) { background: #f3f7f7; }
    .cart-page tbody tr:nth-child(even) { background: #ffffff; }
    .cart-page tbody tr:hover { background: #e5f2f0; }
    .cart-page .select-cell { width: 44px; text-align: center; }
    .cart-page .select-item { width: 18px; height: 18px; accent-color: #0f766e; cursor: pointer; }
    .cart-page .delete-cell { width: 80px; text-align: center; }
    .cart-page .delete-btn { width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center; border: 0; background: transparent; color: #dc2626; cursor: pointer; padding: 5px; border-radius: 8px; }
    .cart-page .delete-btn svg { width: 21px; height: 21px; fill: currentColor; display: block; }
    .cart-page .delete-btn:hover { background: #fee2e2; color: #b91c1c; }
    .cart-page .checkout-btn[aria-disabled="true"] { opacity: .55; cursor: not-allowed; }
</style>

<div class="cart-page">

    <h1>
        Cart
    </h1>

    <?php if (!$items): ?>

        <div class="empty">
            Your cart is empty.
        </div>

    <?php else: ?>

        <div class="cart-msg" id="cartMsg" hidden></div>

        <div class="cart-table-wrap">

            <table class="cart-table">

                <thead>
                    <tr>
                        <th class="select-cell"></th>
                        <th>Medicine Name</th>
                        <th>Quantity</th>
                        <th>Unit Price</th>
                        <th>Total Price</th>
                        <th class="delete-cell"></th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach ($items as $item): ?>
                    <?php $maxQty = max((int)$item['stock'], (int)$item['quantity']); ?>

                    <tr class="<?= (int)$item['is_restricted'] === 1 ? 'restricted-row' : '' ?>"
                        data-id="<?= (int)$item['cart_item_id'] ?>"
                        data-unit="<?= number_format((float)$item['unit_price'], 2, '.', '') ?>"
                        data-max="<?= $maxQty ?>"
                        data-saved="<?= (int)$item['quantity'] ?>">

                        <td class="select-cell">
                            <input type="checkbox" class="select-item" aria-label="Select this medicine for checkout">
                        </td>

                        <td>
                            <?= htmlspecialchars($item['medicine_name']) ?>
                            <?= htmlspecialchars($item['strength'] ?? '') ?>
                            <?php if (!empty($item['pharmacy_name'])): ?><div style="font-size:13px;color:#64748b;margin-top:4px;">Pharmacy: <?= htmlspecialchars($item['pharmacy_name']) ?></div><?php endif; ?>

                            <?php if ((int)$item['is_restricted'] === 1): ?>

                                <div class="restricted-cart-note">
                                    Restricted medicine
                                </div>

                            <?php endif; ?>

                        </td>

                        <td>

                            <div class="qty-stepper">

                                <button type="button" class="qty-btn qty-minus" aria-label="Decrease quantity"<?= (int)$item['quantity'] <= 1 ? ' disabled' : '' ?>>&minus;</button>

                                <input type="number" class="qty-input" value="<?= (int)$item['quantity'] ?>" min="1" max="<?= $maxQty ?>" aria-label="Quantity">

                                <button type="button" class="qty-btn qty-plus" aria-label="Increase quantity"<?= (int)$item['quantity'] >= $maxQty ? ' disabled' : '' ?>>+</button>

                            </div>

                        </td>

                        <td class="unit-price">
                            <?= cart_money($item['unit_price']) ?>
                        </td>

                        <td class="line-total">
                            <?= cart_money($item['line_total']) ?>
                        </td>

                        <td class="delete-cell">
                            <button type="button" class="delete-btn" aria-label="Delete item" title="Delete item"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 3h6l1 2h4v2H4V5h4l1-2zm-3 6h12l-.8 11.2a2 2 0 0 1-2 1.8H8.8a2 2 0 0 1-2-1.8L6 9zm4 2v8h2v-8h-2zm4 0v8h2v-8h-2z"/></svg></button>
                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

                <tfoot>
                    <tr class="cart-total-row">
                        <td colspan="5">Total</td>
                        <td id="cartTotal"><?= cart_money($total) ?></td>
                    </tr>
                </tfoot>

            </table>

        </div>


        <div class="cart-bottom">
            <form method="post" action="checkout.php" id="checkoutForm">
                <div id="selectedInputs"></div>
                <button class="checkout-btn" type="submit" id="checkoutBtn" aria-disabled="true" disabled>🛒 Checkout</button>
            </form>
        </div>

    <?php endif; ?>

</div>

<?php if ($items): ?>
<script>
(function () {
    var table = document.querySelector('.cart-table');
    var msg = document.getElementById('cartMsg');
    var totalEl = document.getElementById('cartTotal');
    var msgTimer = null;
    if (!table) return;

    // 35 -> "35", 2.6 -> "2.60"
    function fmt(n) {
        n = Math.round(n * 100) / 100;
        return (n % 1 === 0) ? n.toLocaleString('en-US') : n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function showMsg(text) {
        msg.textContent = text;
        msg.hidden = false;
        clearTimeout(msgTimer);
        msgTimer = setTimeout(function () { msg.hidden = true; }, 4000);
    }

    function recalcTotal() {
        var t = 0;
        table.querySelectorAll('tbody tr').forEach(function (tr) {
            t += parseFloat(tr.dataset.unit) * parseInt(tr.querySelector('.qty-input').value, 10);
        });
        totalEl.textContent = fmt(t);
    }

    // Shows a quantity in the row and refreshes its total price and the cart total.
    function showQty(tr, q) {
        var max = parseInt(tr.dataset.max, 10);
        tr.querySelector('.qty-input').value = q;
        tr.querySelector('.line-total').textContent = fmt(parseFloat(tr.dataset.unit) * q);
        tr.querySelector('.qty-minus').disabled = q <= 1;
        tr.querySelector('.qty-plus').disabled = q >= max;
        recalcTotal();
    }

    // Saves the quantity on the server a moment after the last click.
    function queueSave(tr, q) {
        var seq = tr._seq = (tr._seq || 0) + 1;
        clearTimeout(tr._timer);
        tr._timer = setTimeout(function () {
            var fd = new FormData();
            fd.append('action', 'update_qty');
            fd.append('cart_item_id', tr.dataset.id);
            fd.append('quantity', q);

            fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (tr._seq !== seq) return;               // a newer change is already on its way
                    if (d.ok) {
                        tr.dataset.max = d.max;
                        tr.dataset.saved = d.quantity;
                        showQty(tr, d.quantity);
                        tr.querySelector('.line-total').textContent = fmt(d.line_total);
                        totalEl.textContent = fmt(d.cart_total);
                        if (d.limited) showMsg('Only ' + d.max + ' in stock for this medicine.');
                    } else {
                        showQty(tr, parseInt(tr.dataset.saved, 10));
                        showMsg(d.error || 'Could not update the quantity.');
                    }
                })
                .catch(function () {
                    if (tr._seq !== seq) return;
                    showQty(tr, parseInt(tr.dataset.saved, 10));
                    showMsg('Could not update the quantity. Please check your connection.');
                });
        }, 250);
    }

    function change(tr, q) {
        var max = parseInt(tr.dataset.max, 10);
        if (isNaN(q) || q < 1) q = 1;
        if (q > max) { q = max; showMsg('Only ' + max + ' in stock for this medicine.'); }
        showQty(tr, q);
        queueSave(tr, q);
    }

    table.addEventListener('click', function (ev) {
        var btn = ev.target.closest('.qty-btn');
        if (!btn) return;
        var tr = btn.closest('tr');
        var cur = parseInt(tr.querySelector('.qty-input').value, 10) || 1;
        change(tr, btn.classList.contains('qty-plus') ? cur + 1 : cur - 1);
    });


    var checkoutForm = document.getElementById('checkoutForm');
    var checkoutBtn = document.getElementById('checkoutBtn');
    var selectedInputs = document.getElementById('selectedInputs');

    function syncCheckoutSelection() {
        selectedInputs.innerHTML = '';
        var selected = table.querySelectorAll('.select-item:checked');
        selected.forEach(function (box) {
            var tr = box.closest('tr');
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_items[]';
            input.value = tr.dataset.id;
            selectedInputs.appendChild(input);
        });
        var hasSelection = selected.length > 0;
        checkoutBtn.disabled = !hasSelection;
        checkoutBtn.setAttribute('aria-disabled', hasSelection ? 'false' : 'true');
    }

    table.addEventListener('change', function (ev) {
        if (ev.target.classList.contains('select-item')) syncCheckoutSelection();
        if (!ev.target.classList.contains('qty-input')) return;
        change(ev.target.closest('tr'), parseInt(ev.target.value, 10));
    });

    table.addEventListener('click', function (ev) {
        var del = ev.target.closest('.delete-btn');
        if (!del) return;
        var tr = del.closest('tr');
        var fd = new FormData();
        fd.append('cart_item_id', tr.dataset.id);
        del.disabled = true;
        fetch('actions/cart_remove.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function () { window.location.reload(); })
            .catch(function () { del.disabled = false; showMsg('Could not delete this item. Please try again.'); });
    });

    syncCheckoutSelection();
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../common/footer.php'; ?>