<?php
session_start();
require_once __DIR__ . '/../database/queries.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login/login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$items = get_favorites($conn, $userId);

/* Same conflict check as browse.php:
   which of my saved medicines conflict with anything already in my cart? */
$cartMedicineIds = array_map(fn($it) => (int)$it['medicine_id'], get_cart($conn, $userId));
$conflictingMedicineIds = get_conflicting_medicine_ids($conn, $cartMedicineIds);
?>
<?php require_once __DIR__ . '/../common/header.php'; ?>

<div class="search-row">
    <form class="search-form" method="get" action="shelf.php">
        <input class="search" name="q" placeholder="search by name, symptoms or category">
    </form>
</div>

<div class="branch-title">
    <h1>My shelf</h1>
</div>
<div class="result-count"><?= count($items) ?> saved medicines</div>

<?php if (!$items): ?>
    <div class="empty">Your shelf is empty.</div>
<?php else: ?>
    <div class="grid">
        <?php foreach ($items as $medicine):
            $pharmacyId   = (int)$medicine['pharmacy_id'];
            $pharmacyName = $medicine['pharmacy_name'] ?? 'Not available';
            $isRestricted = (int)$medicine['is_restricted'] === 1;
            $isConflicting = in_array((int)$medicine['medicine_id'], $conflictingMedicineIds, true);
            $hasConflicts = medicine_has_conflicts($conn, (int)$medicine['medicine_id']);
            $conflictList = $hasConflicts ? get_medicine_conflicts($conn, (int)$medicine['medicine_id']) : [];
            $conflictNames = array_map(fn($c) => medicine_display_name($c['medicine_name'], $c['strength']), $conflictList);
        ?>
            <article class="card">
                <?= medicine_image_html($medicine) ?>
                <div class="card-top">
                    <span class="category"><?= htmlspecialchars($medicine['category_name']) ?></span>
                    <span class="price"><?= $medicine['unit_price'] !== null ? number_format((float)$medicine['unit_price'], 0) : '—' ?>tk.</span>
                </div>
                <div class="med-name-row">
                    <div class="med-name"><?= htmlspecialchars($medicine['medicine_name']) ?></div>
                    <span class="medicine-strength"><?= htmlspecialchars($medicine['strength'] ?? '') ?></span>
                </div>
                <div class="stock-line">
                    <?php $shelfAvail = (int)$medicine['available_stock']; ?>
                    <span class="<?= $shelfAvail > 30 ? 'stock' : 'low' ?>">
                        <?= $shelfAvail > 30 ? 'In stock' : ($shelfAvail > 0 ? 'Low stock' : 'Out of stock') ?>
                    </span>
                    <span class="expiry">Ex. <?= htmlspecialchars($medicine['nearest_expiry_date'] ?? '—') ?></span>
                    <span class="qty">QTY <?= $shelfAvail ?></span>
                </div>
                <div class="medicine-meta">
                    <span><strong>Type:</strong> <?= htmlspecialchars(ucwords(strtolower(str_replace('_', ' ', $medicine['dosage_form'] ?? '')))) ?></span>
                    <span><strong>Company:</strong> <?= htmlspecialchars($medicine['company_name'] ?? '') ?></span>
                </div>

                <?php if ($hasConflicts): ?>
                    <div class="conflict-line">
                        <span class="conflict-flag yes">Conflict: Yes</span>
                        <button type="button" class="conflict-more-link"
                            data-medicine-name="<?= htmlspecialchars($medicine['medicine_name']) ?>"
                            data-conflicts="<?= htmlspecialchars(json_encode($conflictNames), ENT_QUOTES) ?>">Conflict with ›</button>
                    </div>
                <?php endif; ?>

                <div class="pharmacy-line">
                    <span class="pharmacy-label">Pharmacy:</span>
                    <span class="pharmacy-badge"><?= htmlspecialchars($pharmacyName) ?></span>
                    <?php if ($isRestricted): ?><span class="restricted-label">Restricted</span><?php endif; ?>
                </div>
                <div class="desc"><?= htmlspecialchars($medicine['description'] ?? '') ?></div>
                <div class="card-bottom shelf-actions">
                    <form class="remove-form" method="post" action="actions/shelf_remove.php">
                        <input type="hidden" name="favorite_id" value="<?= (int)$medicine['favorite_id'] ?>">
                        <button class="remove-btn" type="submit">Remove</button>
                    </form>
                    <?php if ($isConflicting): ?>
                        <button class="add-btn conflict-disabled" type="button" disabled title="Conflicts with an item already in your cart">⚠ Conflict Medicine</button>
                    <?php elseif ($isRestricted): ?>
                        <button class="add-btn restricted-cart-btn" type="button" <?= (int)$medicine['available_stock'] <= 0 || $pharmacyId <= 0 ? 'disabled' : '' ?>
                            data-medicine="<?= (int)$medicine['medicine_id'] ?>"
                            data-pharmacy="<?= $pharmacyId ?>"
                            data-return-url="<?= htmlspecialchars(basename($_SERVER['PHP_SELF']) . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''), ENT_QUOTES) ?>">Add to cart</button>
                    <?php else: ?>
                        <form class="cart-add-form" method="post" action="actions/cart.php">
                            <input type="hidden" name="medicine_id" value="<?= (int)$medicine['medicine_id'] ?>">
                            <input type="hidden" name="pharmacy_id" value="<?= $pharmacyId ?>">
                            <input type="hidden" name="quantity" value="1">
                            <button class="add-btn" type="submit" <?= (int)$medicine['available_stock'] <= 0 ? 'disabled' : '' ?>>Add to cart</button>
                        </form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../common/footer.php'; ?>