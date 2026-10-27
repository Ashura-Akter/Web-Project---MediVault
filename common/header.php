<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../database/queries.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login/login.php');
    exit;
}

$headerUserName = $_SESSION['full_name'] ?? 'Patient';
$headerRole = strtoupper($_SESSION['role'] ?? 'PATIENT');
$headerInitial = strtoupper(substr($headerUserName, 0, 1));
$currentPage = basename($_SERVER['PHP_SELF']);

$availableBranches = get_pharmacies($conn);

$currentView = $_GET['view'] ?? '';
$currentBranchId = ($currentView === 'branch') ? (int)($_GET['branch'] ?? 0) : 0;
$headerFavoriteIds = ($headerRole === 'PATIENT') ? get_favorite_medicine_ids($conn, (int)$_SESSION['user_id']) : [];
$currentBranchName = $currentBranchName ?? '';
if ($currentBranchName === '' && $currentBranchId > 0) {
    foreach ($availableBranches as $branch) {
        if ((int)$branch['pharmacy_id'] === $currentBranchId) {
            $currentBranchName = $branch['pharmacy_name'];
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MediVault</title>
    <link rel="stylesheet" href="../common/common.css">
    <style>
        /* Role-specific sidebars for pages that use common/header.php */
        .sidebar-admin nav a,
        .sidebar-pharmacist nav a {
            font-size: 12.5px !important;
            min-height: 38px !important;
            padding: 0 12px !important;
            gap: 8px !important;
        }

        .sidebar-admin,
        .sidebar-pharmacist {
            width: 200px !important;
            flex-basis: 200px !important;
        }

        .content.role-wide {
            margin-left: 200px !important;
        }

        @media (max-width:800px) {

            .sidebar-admin,
            .sidebar-pharmacist {
                width: 74px !important;
                flex-basis: 74px !important;
            }

            .sidebar-admin nav a span:not(.nav-icon),
            .sidebar-pharmacist nav a span:not(.nav-icon) {
                display: none !important;
            }

            .content.role-wide {
                margin-left: 74px !important;
            }
        }
    </style>
</head>

<body>
    <?php require_once __DIR__ . '/topbar.php'; ?>

    <div class="app-shell">
        <?php if ($headerRole === 'ADMIN'): ?>
            <aside class="sidebar sidebar-admin">
                <nav>
                    <a class="side-link" href="../admin/index.php?tab=all-medicine"><span class="nav-icon">▦</span><span>Dashboard</span></a>
                    <a class="side-link" href="../admin/index.php?tab=add-medicine"><span class="nav-icon">＋</span><span>Add Medicine</span></a>
                    <a class="side-link" href="../admin/index.php?tab=hidden"><span class="nav-icon">👁</span><span>Hidden</span></a>
                    <a class="side-link" href="../admin/index.php?tab=stock-expiry"><span class="nav-icon">⚠</span><span>Stock &amp; Expiry</span></a>
                    <a class="side-link" href="../admin/index.php?tab=pending-approvals"><span class="nav-icon">✓</span><span>Approvals</span></a>
                    <a class="side-link" href="../admin/index.php?tab=all-users"><span class="nav-icon">♙</span><span>All Users</span></a>
                    <a class="side-link" href="../admin/add_admin.php"><span class="nav-icon">＋</span><span>New Admin</span></a>
                    <a class="side-link" href="../admin/index.php?tab=support"><span class="nav-icon">?</span><span>Support</span></a>
                    <a class="side-link" href="../admin/sales_daily.php"><span class="nav-icon">★</span><span>Daily Sales</span></a>
                    <a class="side-link" href="../admin/sales_history.php"><span class="nav-icon">▤</span><span>Sales History</span></a>
                    <a class="side-link" href="../admin/sales_chart.php"><span class="nav-icon">%</span><span>Chart</span></a>
                </nav>
            </aside>
        <?php elseif ($headerRole === 'PHARMACIST'): ?>
            <aside class="sidebar sidebar-pharmacist">
                <nav>
                    <a class="side-link" href="../pharmasist/medivaultpharmasist.php?tab=all"><span class="nav-icon">▦</span><span>Dashboard</span></a>
                    <a class="side-link" href="../pharmasist/medivaultpharmasist.php?tab=in_stock"><span class="nav-icon">✔</span><span>In Stock</span></a>
                    <a class="side-link" href="../pharmasist/medivaultpharmasist.php?tab=low_stock"><span class="nav-icon">⚠</span><span>Low Stock</span></a>
                    <a class="side-link" href="../pharmasist/medivaultpharmasist.php?tab=out_of_stock"><span class="nav-icon">⊘</span><span>Out of Stock</span></a>
                    <a class="side-link" href="../pharmasist/medivaultpharmasist.php?tab=expiring_soon"><span class="nav-icon">◷</span><span>Expiring</span></a>
                    <a class="side-link" href="../pharmasist/medivaultpharmasist.php?tab=pending"><span class="nav-icon">⏳</span><span>Pending</span></a>
                    <a class="side-link" href="../pharmasist/medivaultpharmasist.php?tab=approved"><span class="nav-icon">✓</span><span>Approved</span></a>
                    <a class="side-link" href="../pharmasist/medivaultpharmasist.php?tab=reservation"><span class="nav-icon">▣</span><span>Reservations</span></a>
                    <a class="side-link" href="../pharmasist/medivaultpharmasist.php?tab=res_history"><span class="nav-icon">◴</span><span>Res. History</span></a>
                    <a class="side-link" href="../pharmasist/medivaultpharmasist.php?tab=order_history"><span class="nav-icon">▤</span><span>Order History</span></a>
                </nav>
            </aside>
        <?php else: ?>
            <aside class="sidebar">
                <nav>
                    <a class="side-link <?= $currentPage === 'browse.php' || $currentBranchName ? 'active' : '' ?>" href="../patient/browse.php?view=all">
                        <span class="nav-icon">▦</span><span>Browse</span>
                    </a>
                    <a class="side-link <?= $currentPage === 'shelf.php' ? 'active' : '' ?>" href="../patient/shelf.php">
                        <span class="nav-icon">♡</span><span>My shelf</span>
                    </a>
                    <a class="side-link <?= $currentPage === 'cart.php' || $currentPage === 'checkout.php' ? 'active' : '' ?>" href="../patient/cart.php">
                        <span class="nav-icon">▣</span><span>Cart</span>
                    </a>
                    <a class="side-link <?= $currentPage === 'orders.php' || $currentPage === 'order_details.php' ? 'active' : '' ?>" href="../patient/orders.php">
                        <span class="nav-icon">◷</span><span>Orders</span>
                    </a>
                    <a class="side-link <?= $currentPage === 'ocr_prescription.php' ? 'active' : '' ?>" href="../patient/ocr_prescription.php">
                        <span class="nav-icon">📸</span><span>Scanner</span>
                    </a>
                    <a class="side-link <?= $currentPage === 'requests.php' ? 'active' : '' ?>" href="../patient/requests.php">
                        <span class="nav-icon">⌁</span><span>Request</span>
                    </a>
                    <div class="branch-nav">
                        <button type="button" class="side-link branch-toggle <?= $currentBranchName ? 'active' : '' ?>" id="branchToggle">
                            <span class="nav-icon">⌖</span><span>Branch</span><span class="branch-arrow" aria-hidden="true"></span>
                        </button>
                        <div id="branchMenu" class="branch-menu">
                            <a href="../patient/browse.php?view=all" class="<?= $currentBranchId === 0 ? 'selected' : '' ?>">All</a>
                            <?php foreach ($availableBranches as $branch): ?>
                                <?php $navBranchId = (int)$branch['pharmacy_id']; ?>
                                <a href="../patient/browse.php?view=branch&branch=<?= $navBranchId ?>" class="<?= $currentBranchId === $navBranchId ? 'selected' : '' ?>">
                                    <?= htmlspecialchars($branch['pharmacy_name']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </nav>
            </aside>
        <?php endif; ?>

        <main class="content<?= ($headerRole === 'ADMIN' || $headerRole === 'PHARMACIST') ? ' role-wide' : '' ?>">