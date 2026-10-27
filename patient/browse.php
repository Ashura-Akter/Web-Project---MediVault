<?php
session_start();
require_once __DIR__ . '/../database/queries.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login/login.php');
    exit;
}
$userId = (int)$_SESSION['user_id'];

$search = trim($_GET['q'] ?? '');
$categoryId = (int)($_GET['category'] ?? 0);
$restricted = isset($_GET['restricted']) && $_GET['restricted'] === '1';
$branchId = (int)($_GET['branch'] ?? 0);
$view = ($_GET['view'] ?? '') === 'branch' && $branchId > 0 ? 'branch' : 'all';
if ($view === 'all') $branchId = 0;
$sort = $_GET['sort'] ?? 'newest';
$allowedSorts = ['newest', 'name', 'expiry', 'price', 'quantity', 'branch'];
if (!in_array($sort, $allowedSorts, true)) $sort = 'newest';
$selectedBranch = null;
if ($branchId > 0) $selectedBranch = get_pharmacy_by_id($conn, $branchId);

$categories = get_categories($conn);
usort($categories, fn($a, $b) => strcasecmp((string)$a['category_name'], (string)$b['category_name']));
$medicines = get_medicines($conn, $search, $categoryId, $restricted, $branchId, $sort);

$selectedCategoryName = '';
foreach ($categories as $category) {
    if ((int)$category['category_id'] === $categoryId) {
        $selectedCategoryName = $category['category_name'];
        break;
    }
}

/* ---- CONFLICT CHECK ----
   Get medicine IDs currently in the active cart, then find every medicine
   that conflicts with ANY of them. Any medicine in this set will show a
   red "Conflict Medicine" badge instead of the Add-to-cart button. */
$cartMedicineIds = array_map(fn($it) => (int)$it['medicine_id'], get_cart($conn, $userId));
$conflictingMedicineIds = get_conflicting_medicine_ids($conn, $cartMedicineIds);
?>
<?php require_once __DIR__ . '/../common/header.php'; ?>

<div class="browse-toolbar">
    <div class="search-row">
        <form class="search-form" method="get" action="browse.php">
            <input type="hidden" name="view" value="<?= $view ?>">
            <input class="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="search by name, symptoms or category" autocomplete="off">
            <?php if ($categoryId): ?><input type="hidden" name="category" value="<?= $categoryId ?>"><?php endif; ?>
            <?php if ($restricted): ?><input type="hidden" name="restricted" value="1"><?php endif; ?>
            <?php if ($view === 'branch' && $branchId): ?><input type="hidden" name="branch" value="<?= $branchId ?>"><?php endif; ?>
            <div class="sort-filter-wrap">
                <select class="sort-filter" id="medicineSortFilter" name="sort" aria-label="Filter medicines" data-view="<?= htmlspecialchars($view, ENT_QUOTES) ?>" data-branch="<?= $view === 'branch' ? (int)$branchId : 0 ?>">
                    <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Filter</option>
                    <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Sort By Name</option>
                    <option value="expiry" <?= $sort === 'expiry' ? 'selected' : '' ?>>Sort By Expire Date</option>
                    <option value="price" <?= $sort === 'price' ? 'selected' : '' ?>>Sort By Price</option>
                    <option value="quantity" <?= $sort === 'quantity' ? 'selected' : '' ?>>Sort By Quantity</option>
                    <option value="branch" <?= $sort === 'branch' ? 'selected' : '' ?>>Sort By Branch</option>
                </select>
            </div>
        </form>
    </div>

    <div class="filters">
        <?php
        $contextParams = 'view=' . urlencode($view);
        if ($view === 'branch' && $branchId > 0) $contextParams .= '&branch=' . $branchId;
        ?>
        <a class="filter <?= (!$categoryId && !$restricted) ? 'active' : '' ?>" href="browse.php?<?= $contextParams ?>&sort=<?= urlencode($sort) ?>">All</a>

        <?php
        $visibleCategoryLimit = 8;
        $barCategories = array_values(array_filter($categories, fn($c) => strcasecmp($c['category_name'], 'Restricted') !== 0));
        $shownCategories = array_slice($barCategories, 0, $visibleCategoryLimit);
        $moreCategories = array_slice($barCategories, $visibleCategoryLimit);
        $moreActive = false;
        foreach ($moreCategories as $mc) {
            if ($categoryId === (int)$mc['category_id']) {
                $moreActive = true;
                break;
            }
        }
        ?>
        <?php foreach ($shownCategories as $category): ?>
            <a class="filter <?= $categoryId === (int)$category['category_id'] ? 'active' : '' ?>"
                href="browse.php?category=<?= (int)$category['category_id'] ?>&<?= $contextParams ?>&sort=<?= urlencode($sort) ?>">
                <?= htmlspecialchars($category['category_name']) ?>
            </a>
        <?php endforeach; ?>

        <a class="filter <?= $restricted ? 'active' : '' ?>" href="browse.php?restricted=1&<?= $contextParams ?>&sort=<?= urlencode($sort) ?>">Restricted</a>

        <?php if ($moreCategories): ?>
            <div class="filter-more" id="filterMore">
                <button type="button" class="filter filter-more-btn <?= $moreActive ? 'active' : '' ?>" id="filterMoreBtn" aria-haspopup="true" aria-expanded="false">
                    More <span class="caret" aria-hidden="true"></span>
                </button>
                <div class="filter-more-menu" id="filterMoreMenu">
                    <?php foreach ($moreCategories as $category): ?>
                        <a class="<?= $categoryId === (int)$category['category_id'] ? 'selected' : '' ?>"
                            href="browse.php?category=<?= (int)$category['category_id'] ?>&<?= $contextParams ?>&sort=<?= urlencode($sort) ?>">
                            <?= htmlspecialchars($category['category_name']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($selectedCategoryName): ?>
    <div class="branch-title">
        <h1><?= htmlspecialchars($selectedCategoryName) ?></h1>
    </div>
<?php endif; ?>

<?php if ($selectedBranch): ?>
    <div class="branch-title">
        <h1><?= htmlspecialchars($selectedBranch['pharmacy_name']) ?></h1>
    </div>
<?php endif; ?>

<div class="result-count"><?= count($medicines) ?> results</div>

<?php if (!$medicines): ?>
    <div class="empty">No medicine matched your search.</div>
<?php else: ?>
    <div class="grid">
        <?php foreach ($medicines as $medicine):
            $availableStock = (int)$medicine['available_stock'];
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
                    <span class="price"><?= $medicine['stock_price'] !== null ? number_format((float)$medicine['stock_price'], 0) : '—' ?>tk.</span>
                </div>

                <div class="med-name-row">
                    <div class="med-name"><?= htmlspecialchars($medicine['medicine_name']) ?></div>
                    <span class="medicine-strength"><?= htmlspecialchars($medicine['strength'] ?? '') ?></span>
                </div>

                <div class="stock-line">
                    <?php if ($availableStock > 0): ?>
                        <span class="<?= $availableStock > 30 ? 'stock' : 'low' ?>">
                            <?= $availableStock > 30 ? 'In stock' : 'Low stock' ?>
                        </span>
                    <?php else: ?>
                        <span class="low" style="background:#eef2ff;color:#4338ca;">Upcoming</span>
                    <?php endif; ?>
                    <span class="expiry">Ex. <?= htmlspecialchars($medicine['nearest_expiry_date'] ?? '—') ?></span>
                    <span class="qty">QTY <?= $availableStock ?></span>
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
                    <span class="pharmacy-badge"><?= htmlspecialchars($medicine['pharmacy_name'] ?? 'Not available') ?></span>
                    <?php if ($isRestricted): ?><span class="restricted-label">Restricted</span><?php endif; ?>
                </div>

                <div class="desc"><?= htmlspecialchars($medicine['description'] ?? '') ?></div>

                <div class="card-bottom">
                    <form class="shelf-toggle-form" method="post" action="actions/shelf.php">
                        <input type="hidden" name="medicine_id" value="<?= (int)$medicine['medicine_id'] ?>">
                        <input type="hidden" name="pharmacy_id" value="<?= (int)($medicine['pharmacy_id'] ?? 0) ?>">
                        <?php $favoriteHere = is_favorite_for_branch($headerFavoriteIds, (int)$medicine['medicine_id'], (int)($medicine['pharmacy_id'] ?? 0)); ?>
                        <button class="heart<?= $favoriteHere ? ' saved' : '' ?>" type="submit" title="<?= $favoriteHere ? 'Remove from My Shelf' : 'Add to My Shelf' ?>" aria-label="<?= $favoriteHere ? 'Remove from My Shelf' : 'Add to My Shelf' ?>">♡</button>
                    </form>

                    <?php if ($isConflicting): ?>
                        <button class="add-btn conflict-disabled" type="button" disabled title="This medicine conflicts with an item already in your cart">⚠ Conflict Medicine</button>
                    <?php elseif ($isRestricted): ?>
                        <button class="add-btn restricted-cart-btn" type="button" <?= $availableStock <= 0 ? 'disabled' : '' ?>
                            data-medicine="<?= (int)$medicine['medicine_id'] ?>"
                            data-pharmacy="<?= (int)($medicine['pharmacy_id'] ?? 0) ?>"
                            data-return-url="<?= htmlspecialchars(basename($_SERVER['PHP_SELF']) . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''), ENT_QUOTES) ?>">Add to cart</button>
                    <?php else: ?>
                        <form class="cart-add-form" method="post" action="actions/cart.php">
                            <input type="hidden" name="medicine_id" value="<?= (int)$medicine['medicine_id'] ?>">
                            <input type="hidden" name="pharmacy_id" value="<?= (int)($medicine['pharmacy_id'] ?? 0) ?>">
                            <input type="hidden" name="quantity" value="1">
                            <button class="add-btn" type="submit" <?= $availableStock <= 0 ? 'disabled' : '' ?>>Add to cart</button>
                        </form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>


<script>
    (function() {
        const sortFilter = document.getElementById('medicineSortFilter');
        if (!sortFilter) return;

        sortFilter.addEventListener('change', function() {
            const url = new URL(window.location.href);
            const view = sortFilter.dataset.view || 'all';
            const branch = parseInt(sortFilter.dataset.branch || '0', 10);

            url.searchParams.set('view', view);
            if (view === 'branch' && branch > 0) {
                url.searchParams.set('branch', String(branch));
            } else {
                url.searchParams.delete('branch');
            }

            url.searchParams.set('sort', sortFilter.value);
            window.location.href = url.toString();
        });
    })();
</script>

<script>
    (function() {
        const wrap = document.getElementById('filterMore');
        const btn = document.getElementById('filterMoreBtn');
        if (!wrap || !btn) return;
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const open = wrap.classList.toggle('open');
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.addEventListener('click', function(e) {
            if (!wrap.contains(e.target)) {
                wrap.classList.remove('open');
                btn.setAttribute('aria-expanded', 'false');
            }
        });
    })();
</script>

<?php require_once __DIR__ . '/../common/footer.php'; ?>