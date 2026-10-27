<?php
session_start();
require_once __DIR__ . '/../database/queries.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login/login.php');
    exit;
}

$branchName = "'Dhaka Pharmacy'";
$branch = get_pharmacy_by_name($conn, $branchName);
$branchId = $branch ? (int)$branch['pharmacy_id'] : 0;
$search = trim($_GET['q'] ?? '');
$categoryId = (int)($_GET['category'] ?? 0);
$restricted = isset($_GET['restricted']) && $_GET['restricted'] === '1';
$categories = get_categories($conn);
$medicines = $branchId ? get_medicines($conn, $search, $categoryId, $restricted, $branchId) : [];
?>
<?php $currentBranchName = $branchName; require_once __DIR__ . '/../common/header.php'; ?>

<div class="branch-title">
    <h1><?= htmlspecialchars($branchName) ?></h1>
    <p><?= $branch ? htmlspecialchars($branch['campus_name']) : 'Branch medicines' ?></p>
</div>

<div class="search-row">
    <form class="search-form" method="get">
        <input class="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="search by name, symptoms or category" autocomplete="off">
    </form>
</div>

<div class="filters">
    <a class="filter <?= (!$categoryId && !$restricted) ? 'active' : '' ?>" href="<?= basename(__FILE__) ?>">All</a>
    <?php foreach ($categories as $category): ?>
        <?php if (strcasecmp($category['category_name'], 'Restricted') === 0) continue; ?>
        <a class="filter <?= $categoryId === (int)$category['category_id'] ? 'active' : '' ?>" href="<?= basename(__FILE__) ?>?category=<?= (int)$category['category_id'] ?>">
            <?= htmlspecialchars($category['category_name']) ?>
        </a>
    <?php endforeach; ?>
    <a class="filter <?= $restricted ? 'active' : '' ?>" href="<?= basename(__FILE__) ?>?restricted=1">Restricted</a>
</div>

<div class="result-count"><?= count($medicines) ?> medicines registered at <?= htmlspecialchars($branchName) ?></div>

<?php if (!$branch): ?>
    <div class="empty">No pharmacy with this name is registered in the current database.</div>
<?php elseif (!$medicines): ?>
    <div class="empty">No medicine is registered at this branch for the selected filter.</div>
<?php else: ?>
<div class="grid">
<?php foreach ($medicines as $medicine): ?>
    <?php $isRestricted=(int)$medicine['is_restricted']===1; $available=(int)$medicine['available_stock']; ?>
    <article class="card">
        <?= medicine_image_html($medicine) ?>
        <div class="card-top"><span class="category"><?= htmlspecialchars($medicine['category_name']) ?></span><span class="price"><?= number_format((float)$medicine['unit_price'],0) ?>tk.</span></div>
        <div class="med-name-row">
            <div class="med-name"><?= htmlspecialchars($medicine['medicine_name']) ?></div>
            <span class="medicine-strength"><?= htmlspecialchars($medicine['strength'] ?? '') ?></span>
        </div>
        <div class="stock-line">
            <span class="<?= $available>30?'stock':($available>0?'low':'low') ?>"><?= $available>30?'In stock':($available>0?'Low stock':'Out of stock') ?></span>
            <span class="expiry">Ex. <?= htmlspecialchars($medicine['nearest_expiry_date'] ?? '—') ?></span>
            <span class="qty">QTY <?= $available ?></span>
        </div>
        <div class="medicine-meta">
            <span><strong>Type:</strong> <?= htmlspecialchars(ucwords(strtolower(str_replace('_', ' ', $medicine['dosage_form'] ?? '')))) ?></span>
            <span><strong>Company:</strong> <?= htmlspecialchars($medicine['company_name'] ?? '') ?></span>
        </div>
        <div class="pharmacy-line"><span class="pharmacy-label">Pharmacy:</span><span class="pharmacy-badge"><?= htmlspecialchars($branchName) ?></span><?php if ($isRestricted): ?><span class="restricted-label">Restricted</span><?php endif; ?></div>
        <div class="desc"><?= htmlspecialchars($medicine['description'] ?? '') ?></div>
        <div class="card-bottom">
            <form class="shelf-toggle-form" method="post" action="actions/shelf.php">
                <input type="hidden" name="medicine_id" value="<?= (int)$medicine['medicine_id'] ?>">
                <input type="hidden" name="pharmacy_id" value="<?= (int)$branchId ?>">
                <?php $favoriteHere = is_favorite_for_branch($headerFavoriteIds, (int)$medicine['medicine_id'], (int)$branchId); ?>
                <button class="heart<?= $favoriteHere ? ' saved' : '' ?>" type="submit" title="<?= $favoriteHere ? 'Remove from My Shelf' : 'Add to My Shelf' ?>" aria-label="<?= $favoriteHere ? 'Remove from My Shelf' : 'Add to My Shelf' ?>">♡</button>
            </form>
            <?php if ($isRestricted): ?>
                <button class="add-btn restricted-cart-btn" type="button" <?= $available<=0?'disabled':'' ?>
                        data-medicine="<?= (int)$medicine['medicine_id'] ?>"
                        data-pharmacy="<?= (int)$branchId ?>"
                        data-return-url="<?= htmlspecialchars(basename($_SERVER['PHP_SELF']) . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''), ENT_QUOTES) ?>">Add to cart</button>
            <?php else: ?>
                <form class="cart-add-form" method="post" action="actions/cart.php"><input type="hidden" name="medicine_id" value="<?= (int)$medicine['medicine_id'] ?>"><input type="hidden" name="pharmacy_id" value="<?= (int)$pharmacyId ?>"><input type="hidden" name="quantity" value="1"><button class="add-btn" type="submit" <?= $available<=0?'disabled':'' ?>>Add to cart</button></form>
            <?php endif; ?>
        </div>
    </article>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../common/footer.php'; ?>
