<?php
session_start();
require_once __DIR__ . '/../database/connection.php';

/* -------- Live search suggestions (AJAX endpoint) -------- */
if (isset($_GET['suggest'])) {
  header('Content-Type: application/json; charset=utf-8');
  if (!isset($_SESSION['user_id']) || strtoupper($_SESSION['role'] ?? '') !== 'ADMIN') {
    echo json_encode([]);
    exit;
  }
  $sq = trim((string)($_GET['q'] ?? ''));
  if ($sq === '') {
    echo json_encode([]);
    exit;
  }
  $like = '%' . $sq . '%';
  $prefix = $sq . '%';
  $out = [];
  $st = $conn->prepare("SELECT m.medicine_name AS label, (m.medicine_name LIKE ?) AS pref FROM medicines m LEFT JOIN categories c ON c.category_id = m.category_id WHERE m.is_active = TRUE AND (m.medicine_name LIKE ? OR m.generic_name LIKE ? OR m.company_name LIKE ? OR c.category_name LIKE ?) ORDER BY pref DESC, m.medicine_name ASC LIMIT 10");
  $st->bind_param('sssss', $prefix, $like, $like, $like, $like);
  $st->execute();
  foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $out[mb_strtolower($r['label'])] = $r['label'];
  echo json_encode(array_values(array_slice($out, 0, 8)));
  exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../database/queries.php';
$tab = $_GET['tab'] ?? 'all-medicine';
$allowed = ['all-medicine', 'hidden', 'stock-expiry', 'pending-approvals', 'all-users', 'add-medicine', 'support'];
if (!in_array($tab, $allowed, true)) $tab = 'all-medicine';
$q = trim((string)($_GET['q'] ?? ''));
$roleFilter = strtoupper((string)($_GET['role'] ?? 'ALL'));
if (!in_array($roleFilter, ['ALL', 'PATIENT', 'PHARMACIST', 'ADMIN'], true)) $roleFilter = 'ALL';
function money($x)
{
  return number_format((float)$x, 2) . ' tk.';
}
function daysUntil($d)
{
  return (int)floor((strtotime($d) - strtotime(date('Y-m-d'))) / 86400);
}
$totalMedicine = (int)$conn->query("SELECT COUNT(*) FROM medicines WHERE is_active=TRUE")->fetch_column();
$hiddenCount = (int)$conn->query("SELECT COUNT(*) FROM medicines WHERE is_active=FALSE")->fetch_column();
$lowStockCount = (int)$conn->query("SELECT COUNT(*) FROM (SELECT m.medicine_id FROM medicines m LEFT JOIN inventory_batches ib ON ib.medicine_id=m.medicine_id WHERE m.is_active=TRUE GROUP BY m.medicine_id,m.reorder_level HAVING COALESCE(SUM(GREATEST(ib.quantity_on_hand-ib.reserved_quantity,0)),0)<=m.reorder_level) x")->fetch_column();
$expiryCount = (int)$conn->query("SELECT COUNT(DISTINCT m.medicine_id) FROM medicines m JOIN inventory_batches ib ON ib.medicine_id=m.medicine_id WHERE m.is_active=TRUE AND ib.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 90 DAY)")->fetch_column();
$totalUsers = (int)$conn->query("SELECT COUNT(*) FROM users WHERE is_active=TRUE")->fetch_column();
$pendingCount = (int)$conn->query("SELECT COUNT(*) FROM pharmacist_profiles WHERE approval_status='PENDING'")->fetch_column();
$adminId = (int)$_SESSION['user_id'];
$flash = $_SESSION['flash'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash'], $_SESSION['flash_error']);
$medicines = [];
$hiddenMedicines = [];
$lowItems = [];
$expiring = [];
$pending = [];
$users = [];
$support = [];
$allMedicinesForPicker = [];
if ($tab === 'all-medicine' || $tab === 'hidden' || $tab === 'add-medicine') {
  $allRes = $conn->query("SELECT medicine_id, medicine_name, strength, company_name FROM medicines WHERE is_active = TRUE ORDER BY medicine_name ASC");
  $allMedicinesForPicker = $allRes ? $allRes->fetch_all(MYSQLI_ASSOC) : [];
}
if ($tab === 'all-medicine' || $tab === 'hidden') {
  $activeFlag = ($tab === 'hidden') ? 'FALSE' : 'TRUE';
  $sql = "SELECT m.*,c.category_name,COALESCE(SUM(CASE WHEN ib.expiry_date>CURDATE() THEN GREATEST(ib.quantity_on_hand-ib.reserved_quantity,0) ELSE 0 END),0) stock_qty,MIN(CASE WHEN ib.expiry_date>CURDATE() THEN ib.expiry_date ELSE NULL END) nearest_expiry FROM medicines m LEFT JOIN categories c ON c.category_id=m.category_id LEFT JOIN inventory_batches ib ON ib.medicine_id=m.medicine_id WHERE m.is_active=$activeFlag";
  $params = [];
  $types = '';
  if ($q !== '') {
    $sql .= " AND (m.medicine_name LIKE ? OR m.generic_name LIKE ? OR c.category_name LIKE ? OR m.company_name LIKE ?)";
    $like = '%' . $q . '%';
    $params = [$like, $like, $like, $like];
    $types = 'ssss';
  }
  $sql .= " GROUP BY m.medicine_id ORDER BY m.medicine_id DESC";
  $s = $conn->prepare($sql);
  if ($types) $s->bind_param($types, ...$params);
  $s->execute();
  $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
  if ($tab === 'hidden') $hiddenMedicines = $rows;
  else $medicines = $rows;
} elseif ($tab === 'stock-expiry') {
  $s = $conn->query("SELECT m.medicine_id,m.medicine_name,m.reorder_level,COALESCE(SUM(GREATEST(ib.quantity_on_hand-ib.reserved_quantity,0)),0) stock_qty FROM medicines m LEFT JOIN inventory_batches ib ON ib.medicine_id=m.medicine_id WHERE m.is_active=TRUE GROUP BY m.medicine_id,m.medicine_name,m.reorder_level HAVING stock_qty<=m.reorder_level ORDER BY stock_qty,m.medicine_name");
  $lowItems = $s ? $s->fetch_all(MYSQLI_ASSOC) : [];
  $s = $conn->query("SELECT m.medicine_name,ib.expiry_date,DATEDIFF(ib.expiry_date,CURDATE()) days_left FROM medicines m JOIN inventory_batches ib ON ib.medicine_id=m.medicine_id WHERE m.is_active=TRUE AND ib.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 90 DAY) ORDER BY ib.expiry_date,m.medicine_name");
  $expiring = $s ? $s->fetch_all(MYSQLI_ASSOC) : [];
} elseif ($tab === 'pending-approvals') {
  $s = $conn->query("SELECT pp.pharmacist_profile_id,pp.pharmacy_or_company_name,pp.license_no,pp.registered_at,u.full_name,u.email,u.phone,p.pharmacy_name FROM pharmacist_profiles pp JOIN users u ON u.user_id=pp.user_id LEFT JOIN pharmacies p ON p.pharmacy_id=pp.pharmacy_id WHERE pp.approval_status='PENDING' AND u.is_active=TRUE ORDER BY pp.registered_at");
  $pending = $s ? $s->fetch_all(MYSQLI_ASSOC) : [];
} elseif ($tab === 'all-users') {
  $sql = "SELECT u.*, pp.pharmacy_or_company_name AS profile_pharmacy_name, ph.pharmacy_name AS linked_pharmacy_name FROM users u LEFT JOIN pharmacist_profiles pp ON pp.user_id = u.user_id LEFT JOIN pharmacies ph ON ph.pharmacy_id = pp.pharmacy_id WHERE 1=1";
  $params = [];
  $types = '';
  if ($roleFilter !== 'ALL') {
    $sql .= " AND u.role=?";
    $params[] = $roleFilter;
    $types .= 's';
  }
  if ($q !== '') {
    $sql .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
    $types .= 'sss';
  }
  $sql .= " ORDER BY u.is_active DESC, u.user_id";
  $s = $conn->prepare($sql);
  if ($types) $s->bind_param($types, ...$params);
  $s->execute();
  $users = $s->get_result()->fetch_all(MYSQLI_ASSOC);
} elseif ($tab === 'support') {
  $s = $conn->query("SELECT n.notification_id, n.title, n.message, n.is_read, n.created_at, n.user_id AS admin_recipient_id, (SELECT COUNT(*) FROM support_replies sr WHERE sr.original_notification_id = n.notification_id) AS reply_count FROM notifications n WHERE n.notification_type='GENERAL' AND n.title='Help & Support Request' ORDER BY n.created_at DESC");
  if ($s) $support = $s->fetch_all(MYSQLI_ASSOC);
  foreach ($support as &$sp) {
    $senderId = 0;
    if (!empty($sp['message']) && preg_match('/User ID:\s*(\d+)/', (string)$sp['message'], $m)) {
      $senderId = (int)$m[1];
    }
    if ($senderId > 0) {
      $ss = $conn->prepare("SELECT full_name, role FROM users WHERE user_id=? LIMIT 1");
      $ss->bind_param('i', $senderId);
      $ss->execute();
      $sr = $ss->get_result()->fetch_assoc();
      if ($sr) {
        $sp['sender_full_name'] = $sr['full_name'];
        $sp['sender_role'] = $sr['role'];
        $sp['sender_user_id'] = $senderId;
      }
    }
  }
  unset($sp);
}
$readTypes = [];
$readTitles = [];
if ($tab === 'pending-approvals') {
  $readTypes = ['PHARMACIST_APPROVAL'];
  $readTitles = ['New Pharmacist Registration'];
} elseif ($tab === 'stock-expiry') {
  $readTypes = ['LOW_STOCK', 'EXPIRY_WARNING'];
} elseif ($tab === 'all-users' && in_array($roleFilter, ['ALL', 'PATIENT'], true)) {
  $readTitles = ['New Patient Registration'];
}
mark_notifications_read($conn, $adminId, $readTypes, $readTitles);
$roleCounts = ['ALL' => $totalUsers, 'PATIENT' => 0, 'PHARMACIST' => 0, 'ADMIN' => 0];
foreach (['PATIENT', 'PHARMACIST', 'ADMIN'] as $r) {
  $st = $conn->prepare("SELECT COUNT(*) c FROM users WHERE role=? AND is_active=TRUE");
  $st->bind_param('s', $r);
  $st->execute();
  $roleCounts[$r] = (int)$st->get_result()->fetch_assoc()['c'];
}
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>MediVault - Admin Dashboard</title>
  <link rel="stylesheet" href="../common/common.css">
  <link rel="stylesheet" href="assets/style.css">
  <style>
    /* ============ Conflict UI (Admin) ============ */
    .conflict-block {
      margin-top: 16px;
      padding: 16px 18px;
      background: #fef2f2;
      border: 1.5px solid #fecaca;
      border-radius: 14px
    }

    .conflict-block h3 {
      margin: 0 0 10px;
      font-size: 14px;
      font-weight: 800;
      color: #991b1b;
      display: flex;
      align-items: center;
      gap: 8px
    }

    .conflict-block h3 .count {
      background: #fee2e2;
      color: #991b1b;
      font-size: 11px;
      padding: 2px 8px;
      border-radius: 10px
    }

    .conflict-picker {
      position: relative;
      margin-bottom: 10px
    }

    .conflict-trigger {
      width: 100%;
      box-sizing: border-box;
      padding: 11px 14px;
      background: #fff;
      border: 1.5px solid #fecaca;
      border-radius: 10px;
      font-size: 14px;
      font-family: inherit;
      text-align: left;
      cursor: pointer;
      color: #64748b
    }

    .conflict-trigger:hover {
      border-color: #ef4444
    }

    .conflict-menu {
      position: absolute;
      top: calc(100% + 4px);
      left: 0;
      right: 0;
      background: #fff;
      border: 1.5px solid #fecaca;
      border-radius: 12px;
      box-shadow: 0 12px 32px rgba(15, 23, 42, .16);
      z-index: 5000;
      display: none;
      overflow: hidden
    }

    .conflict-menu.open {
      display: block
    }

    .conflict-menu-search {
      width: 100%;
      box-sizing: border-box;
      padding: 11px 14px;
      border: 0;
      border-bottom: 1px solid #f1f5f9;
      font-size: 14px;
      font-family: inherit;
      outline: none
    }

    .conflict-menu-list {
      max-height: 240px;
      overflow-y: auto
    }

    .conflict-option {
      padding: 10px 14px;
      font-size: 13.5px;
      cursor: pointer;
      border-bottom: 1px solid #f8fafc;
      display: flex;
      justify-content: space-between;
      gap: 10px;
      align-items: center
    }

    .conflict-option:last-child {
      border-bottom: 0
    }

    .conflict-option:hover {
      background: #fef2f2;
      color: #991b1b
    }

    .conflict-option small {
      color: #64748b;
      font-size: 11.5px
    }

    .conflict-chip-list {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 8px
    }

    .conflict-chip {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 7px 12px;
      background: #fff;
      border: 1.5px solid #fecaca;
      border-radius: 20px;
      font-size: 13px;
      color: #991b1b;
      font-weight: 700
    }

    .conflict-chip button {
      border: 0;
      background: transparent;
      color: #ef4444;
      font-size: 16px;
      line-height: 1;
      cursor: pointer;
      padding: 0;
      font-weight: 800
    }

    .conflict-chip button:hover {
      color: #b91c1c
    }

    .conflict-empty {
      font-size: 12px;
      color: #94a3b8;
      font-style: italic;
      margin-top: 6px;
      display: block
    }

    /* Yes/No selector */
    .conflict-yn {
      display: flex;
      gap: 10px
    }

    .conflict-yn label {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 8px 14px;
      background: #fff;
      border: 1.5px solid #e2e8f0;
      border-radius: 10px;
      cursor: pointer;
      font-size: 13px;
      font-weight: 700;
      color: #475569
    }

    .conflict-yn label:hover {
      border-color: #94a3b8
    }

    .conflict-yn input {
      display: none
    }

    .conflict-yn input:checked+label,
    .conflict-yn label.active {
      border-color: #078f86;
      background: #f0faf8;
      color: #078f86
    }

    /* Conflict badge on admin medicine card */
    .conflict-badge-sm {
      background: #fee2e2;
      color: #b91c1c;
      border: 1px solid #fca5a5;
      font-size: 11px;
      font-weight: 800;
      padding: 3px 8px;
      border-radius: 8px;
      cursor: pointer;
      border-style: solid
    }

    .conflict-badge-sm:hover {
      background: #fecaca
    }

    /* ============ Support card states ============ */
    .support-card.unreplied {
      border-left: 4px solid #f59e0b !important;
      background: #fffbeb !important
    }

    .support-card.replied {
      border-left: 4px solid #16a34a !important;
      background: #f0fdf4 !important;
      opacity: .88
    }

    .support-replied-badge {
      background: #dcfce7;
      color: #166534;
      font-size: 11px;
      font-weight: 800;
      padding: 3px 8px;
      border-radius: 8px;
      margin-left: 8px
    }

    .support-meta {
      font-size: 12.5px;
      color: #64748b;
      margin: 8px 0 12px
    }

    .support-actions {
      display: flex;
      gap: 8px;
      margin-top: 12px;
      flex-wrap: wrap
    }

    .support-reply-btn {
      background: #078f86;
      color: #fff;
      border: 0;
      padding: 8px 16px;
      border-radius: 10px;
      font-weight: 700;
      font-size: 13px;
      cursor: pointer
    }

    .support-reply-btn:hover {
      background: #057c74
    }

    .support-reply-btn.disabled {
      background: #cbd5e1 !important;
      color: #64748b !important;
      cursor: not-allowed !important
    }

    /* ============ Reply Modal (Admin) ============ */
    .reply-modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, .55);
      backdrop-filter: blur(3px);
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
      z-index: 100000
    }

    .reply-modal-overlay.show {
      display: flex
    }

    .reply-modal {
      position: relative;
      width: min(560px, 100%);
      max-height: calc(100vh - 40px);
      overflow: auto;
      background: #fff;
      border-radius: 20px;
      padding: 28px 30px;
      box-shadow: 0 24px 60px rgba(15, 23, 42, .35)
    }

    .reply-modal h2 {
      margin: 0 0 14px;
      font-size: 19px;
      font-weight: 800;
      color: #0f172a
    }

    .reply-modal .orig-msg {
      background: #f1f5f9;
      padding: 12px 14px;
      border-radius: 10px;
      font-size: 13.5px;
      line-height: 1.5;
      color: #334155;
      margin-bottom: 16px;
      word-break: break-word
    }

    .reply-modal textarea {
      width: 100%;
      box-sizing: border-box;
      min-height: 130px;
      padding: 12px 14px;
      border: 1.5px solid #e2e8f0;
      border-radius: 12px;
      font-size: 14px;
      font-family: inherit;
      outline: none;
      resize: vertical
    }

    .reply-modal textarea:focus {
      border-color: #078f86;
      box-shadow: 0 0 0 3px rgba(7, 143, 134, .12)
    }

    .reply-modal-actions {
      display: flex;
      gap: 10px;
      justify-content: flex-end;
      margin-top: 16px
    }

    .reply-modal-actions button {
      border: 0;
      border-radius: 12px;
      padding: 11px 22px;
      font-size: 14px;
      font-weight: 800;
      cursor: pointer;
      font-family: inherit
    }

    .reply-cancel {
      background: #f1f5f9;
      color: #475569
    }

    .reply-send {
      background: linear-gradient(135deg, #078f86, #0e7490);
      color: #fff;
      box-shadow: 0 8px 20px rgba(7, 143, 134, .28)
    }

    /* ============ Edit Conflicts Modal ============ */
    .edit-conflict-modal .conflict-block {
      margin-top: 0
    }
  </style>
</head>

<body>
  <?php require_once __DIR__ . '/../common/topbar.php'; ?>
  <div class="admin-layout">
    <aside class="admin-sidebar">
      <nav><a class="<?= $tab === 'all-medicine' ? 'active' : '' ?>" href="?tab=all-medicine">▦ <span>Dashboard</span></a><a class="<?= $tab === 'add-medicine' ? 'active' : '' ?>" href="?tab=add-medicine">＋ <span>Add Medicine</span></a><a class="<?= $tab === 'hidden' ? 'active' : '' ?>" href="?tab=hidden">👁 <span>Hidden<?= $hiddenCount ? ' (' . $hiddenCount . ')' : '' ?></span></a><a class="<?= $tab === 'stock-expiry' ? 'active' : '' ?>" href="?tab=stock-expiry">⚠ <span>Stock &amp; Expiry</span></a><a class="<?= $tab === 'pending-approvals' ? 'active' : '' ?>" href="?tab=pending-approvals">✓ <span>Pharmacist Approvals<?= $pendingCount ? ' (' . $pendingCount . ')' : '' ?></span></a><a class="<?= $tab === 'all-users' ? 'active' : '' ?>" href="?tab=all-users">♙ <span>All Users</span></a><a href="add_admin.php">＋ <span>Add New Admin</span></a><a class="<?= $tab === 'support' ? 'active' : '' ?>" href="?tab=support">? <span>Support Requests</span></a><a href="sales_daily.php">★ <span>Daily Top Sales</span></a><a href="sales_history.php">▤ <span>Sales History</span></a><a href="sales_chart.php">% <span>Top Selling Chart</span></a></nav>
    </aside>
    <main class="admin-main">
      <div class="sticky-tools">
        <h1>Admin Dashboard</h1>
        <?php if ($tab === 'all-medicine'): ?>
          <div class="search-wrap">
            <form class="search" method="get" id="adminSearchForm" autocomplete="off"><input type="hidden" name="tab" value="<?= h($tab) ?>"><input name="q" id="adminSearchInput" value="<?= h($q) ?>" placeholder="search by name, symptoms or category" autocomplete="off"></form>
            <div class="admin-suggest-box" id="adminSuggestBox" hidden></div>
          </div>
        <?php endif; ?>
      </div>
      <?php if ($flash): ?><div class="flash success"><?= h($flash) ?></div><?php endif; ?><?php if ($flashError): ?><div class="flash error"><?= h($flashError) ?></div><?php endif; ?>

      <?php if ($tab === 'all-medicine'): ?>
        <section class="metrics">
          <a href="?tab=all-medicine"><strong><?= $totalMedicine ?></strong><span>Total Medicine</span></a>
          <a href="?tab=stock-expiry"><strong class="orange"><?= $lowStockCount ?></strong><span>Low stock alert</span></a>
          <a href="?tab=stock-expiry"><strong class="red"><?= $expiryCount ?></strong><span>Expiring in 90 days</span></a>
          <a href="?tab=all-users"><strong class="teal"><?= $totalUsers ?></strong><span>Registered Users</span></a>
        </section>
      <?php endif; ?>

      <?php if ($tab === 'all-medicine'): ?>
        <div class="section-head">
          <h2>All Medicine</h2><span><?= count($medicines) ?> results</span>
        </div>
        <section class="medicine-grid">
          <?php foreach ($medicines as $m): $stock = (int)$m['stock_qty'];
            $low = $stock <= (int)$m['reorder_level'];
            $days = $m['nearest_expiry'] ? daysUntil($m['nearest_expiry']) : null;
            $soon = $days !== null && $days >= 0 && $days <= 90; ?>
            <article class="medicine-card"><?= render_admin_medicine_card($m, $q, $low, $soon, 'active') ?></article>
          <?php endforeach; ?>
        </section>
        <?php if (!$medicines): ?><div class="empty">No medicines match your search.</div><?php endif; ?>

      <?php elseif ($tab === 'hidden'): ?>
        <div class="section-head">
          <h2>Hidden Medicine</h2><span><?= count($hiddenMedicines) ?> hidden</span>
        </div>
        <section class="medicine-grid">
          <?php foreach ($hiddenMedicines as $m): $stock = (int)$m['stock_qty'];
            $low = $stock <= (int)$m['reorder_level'];
            $days = $m['nearest_expiry'] ? daysUntil($m['nearest_expiry']) : null;
            $soon = $days !== null && $days >= 0 && $days <= 90; ?>
            <article class="medicine-card inactive"><?= render_admin_medicine_card($m, $q, $low, $soon, 'hidden') ?></article>
          <?php endforeach; ?>
        </section>
        <?php if (!$hiddenMedicines): ?><div class="empty">No hidden medicines.</div><?php endif; ?>

      <?php elseif ($tab === 'add-medicine'): ?>
        <?php
        $cats = $conn->query("SELECT category_id,category_name FROM categories WHERE category_name<>'Restricted' ORDER BY category_name")->fetch_all(MYSQLI_ASSOC);
        $companies = $conn->query("SELECT DISTINCT company_name FROM medicines WHERE company_name<>'' ORDER BY company_name")->fetch_all(MYSQLI_ASSOC);
        $fieldStyle = 'width:100%;padding:11px;border:1px solid #ddd;border-radius:10px;background:#fff;';
        $labelStyle = 'display:block;font-size:12px;font-weight:700;margin-bottom:6px;';
        ?>
        <div class="section-head">
          <h2>Add Medicine</h2><span>Admin only</span>
        </div>
        <section class="medicine-card" style="max-width:760px;min-height:0;">
          <form method="post" action="actions.php" enctype="multipart/form-data" style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
            <input type="hidden" name="action" value="add_medicine"><input type="hidden" name="tab" value="add-medicine">
            <input type="hidden" name="conflicts_json" id="conflictsJson" value="[]">
            <input type="hidden" name="has_conflict" id="hasConflictHidden" value="no">

            <div><label style="<?= $labelStyle ?>">Medicine Name</label><input name="medicine_name" required style="<?= $fieldStyle ?>"></div>
            <div><label style="<?= $labelStyle ?>">Strength (mg)</label><input name="strength" placeholder="e.g. 500mg" required style="<?= $fieldStyle ?>"></div>

            <div>
              <label style="<?= $labelStyle ?>">Type</label>
              <select name="dosage_form" id="typeSelect" required style="<?= $fieldStyle ?>">
                <option value="TABLET">Tablet</option>
                <option value="CAPSULE">Capsule</option>
                <option value="SYRUP">Syrup</option>
                <option value="CREAM">Cream</option>
                <option value="INJECTION">Injection</option>
                <option value="OINTMENT">Ointment</option>
                <option value="DROPS">Drops</option>
                <option value="SOLUTION">Solution</option>
                <option value="INHALER">Inhaler</option>
                <option value="LOZENGE">Lozenge</option>
                <option value="__OTHER__">Others</option>
              </select>
              <input name="dosage_form_other" id="typeOther" maxlength="60" placeholder="Write the type (e.g. Spray)" style="<?= $fieldStyle ?>margin-top:8px;display:none;">
            </div>
            <div>
              <label style="<?= $labelStyle ?>">Category</label>
              <select name="category_id" id="categorySelect" required style="<?= $fieldStyle ?>">
                <option value="">Select category</option>
                <?php foreach ($cats as $c): ?><option value="<?= $c['category_id'] ?>"><?= h($c['category_name']) ?></option><?php endforeach; ?>
                <option value="__OTHER__">Others</option>
              </select>
              <input name="category_other" id="categoryOther" maxlength="80" placeholder="Write the new category name" style="<?= $fieldStyle ?>margin-top:8px;display:none;">
            </div>

            <div>
              <label style="<?= $labelStyle ?>">Company Name</label>
              <select name="company_name" id="companySelect" required style="<?= $fieldStyle ?>">
                <option value="">Select company</option>
                <?php foreach ($companies as $co): ?><option value="<?= h($co['company_name']) ?>"><?= h($co['company_name']) ?></option><?php endforeach; ?>
                <option value="__OTHER__">Others</option>
              </select>
              <input name="company_name_other" id="companyOther" maxlength="150" placeholder="Write the company name" style="<?= $fieldStyle ?>margin-top:8px;display:none;">
            </div>
            <div><label style="<?= $labelStyle ?>">Restricted?</label><select name="is_restricted" required style="<?= $fieldStyle ?>">
                <option value="0">Not Restricted</option>
                <option value="1">Restricted</option>
              </select></div>

            <div style="grid-column:1/-1;">
              <label style="<?= $labelStyle ?>">Medicine Image <span style="font-weight:400;color:#888">(file OR URL)</span></label>
              <div style="display:grid;grid-template-columns:160px 1fr;gap:14px;align-items:start;">
                <div class="mv-med-img" id="imgPreviewBox" style="width:160px;height:120px;margin:0;"><img id="imgPreview" alt="" style="display:none"><span id="imgPreviewHint" style="font-size:11px;color:#8a9a98;text-align:center;padding:6px">Image preview</span></div>
                <div>
                  <input type="file" name="medicine_image" id="medicineImage" accept="image/jpeg,image/png,image/webp,image/gif" style="<?= $fieldStyle ?>">
                  <div style="font-size:11px;color:#888;text-align:center;margin:4px 0;">— OR —</div>
                  <input type="url" name="medicine_image_url" id="medicineImageUrl" placeholder="https://example.com/medicine.jpg" style="<?= $fieldStyle ?>">
                </div>
              </div>
            </div>

            <div style="grid-column:1/-1;"><label style="<?= $labelStyle ?>">Description</label><textarea name="description" rows="4" style="<?= $fieldStyle ?>resize:vertical;"></textarea></div>

            <!-- ============ CONFLICT YES/NO ============ -->
            <div style="grid-column:1/-1;">
              <label style="<?= $labelStyle ?>">Conflict?</label>
              <div class="conflict-yn">
                <input type="radio" name="conflict_choice" id="conflictNo" value="no" checked>
                <label for="conflictNo" id="conflictNoLabel" class="active">No — No conflicts</label>
                <input type="radio" name="conflict_choice" id="conflictYes" value="yes">
                <label for="conflictYes" id="conflictYesLabel">Yes — Add conflicting medicines</label>
              </div>
            </div>

            <!-- ============ CONFLICT WITH SECTION (single picker, multiple chips) ============ -->
            <div style="grid-column:1/-1;" id="conflictSectionWrap" hidden>
              <div class="conflict-block">
                <h3>⚠️ Conflict With <span class="count" id="conflictCount">0</span></h3>
                <div class="conflict-picker" id="conflictPicker" data-index="0">
                  <button type="button" class="conflict-trigger" onclick="openConflictPicker(this)">Select medicine to conflict with…</button>
                  <div class="conflict-menu">
                    <input type="text" class="conflict-menu-search" placeholder="Search medicine…" oninput="filterConflictList(this)">
                    <div class="conflict-menu-list"></div>
                  </div>
                </div>
                <div class="conflict-chip-list" id="conflictChipList"></div>
              </div>
            </div>

            <div style="grid-column:1/-1;text-align:center;"><button class="approve" style="max-width:300px;" type="submit">SAVE MEDICINE</button></div>
          </form>
        </section>

        <script>
          var ALL_MEDICINES = <?= json_encode(array_map(fn($m) => ['id' => (int)$m['medicine_id'], 'name' => $m['medicine_name'], 'strength' => $m['strength'] ?? '', 'company' => $m['company_name'] ?? ''], $allMedicinesForPicker)) ?>;

          (function() {
            function wire(selectId, inputId) {
              var sel = document.getElementById(selectId),
                box = document.getElementById(inputId);
              if (!sel || !box) return;

              function sync() {
                var on = sel.value === '__OTHER__';
                box.style.display = on ? 'block' : 'none';
                box.required = on;
                if (!on) box.value = '';
                else box.focus();
              }
              sel.addEventListener('change', sync);
              sync();
            }
            wire('typeSelect', 'typeOther');
            wire('categorySelect', 'categoryOther');
            wire('companySelect', 'companyOther');

            var f = document.getElementById('medicineImage'),
              u = document.getElementById('medicineImageUrl');
            var im = document.getElementById('imgPreview'),
              hint = document.getElementById('imgPreviewHint');

            function showPreview(src) {
              im.src = src;
              im.style.display = 'block';
              hint.style.display = 'none';
            }

            function clearPreview() {
              im.style.display = 'none';
              im.src = '';
              hint.style.display = 'block';
            }
            if (f) f.addEventListener('change', function() {
              var file = f.files && f.files[0];
              if (!file) {
                clearPreview();
                return;
              }
              if (u) u.value = '';
              showPreview(URL.createObjectURL(file));
            });
            if (u) u.addEventListener('input', function() {
              var url = u.value.trim();
              if (url === '') {
                clearPreview();
                return;
              }
              if (f) f.value = '';
              showPreview(url);
            });

            /* ============ Conflict Yes/No ============ */
            var yesR = document.getElementById('conflictYes');
            var noR = document.getElementById('conflictNo');
            var yesL = document.getElementById('conflictYesLabel');
            var noL = document.getElementById('conflictNoLabel');
            var secWrap = document.getElementById('conflictSectionWrap');
            var hidden = document.getElementById('hasConflictHidden');

            function syncConflictYN() {
              var yes = yesR.checked;
              yesL.classList.toggle('active', yes);
              noL.classList.toggle('active', !yes);
              secWrap.hidden = !yes;
              hidden.value = yes ? 'yes' : 'no';
            }
            yesR.addEventListener('change', syncConflictYN);
            noR.addEventListener('change', syncConflictYN);
            syncConflictYN();

            /* ============ Conflict picker (single box, multiple chips) ============ */
            var chipList = document.getElementById('conflictChipList');
            var countEl = document.getElementById('conflictCount');
            var hiddenJson = document.getElementById('conflictsJson');
            var conflictSelections = [];

            function refreshHidden() {
              hiddenJson.value = JSON.stringify(conflictSelections.map(function(x) {
                return x.id;
              }));
              countEl.textContent = conflictSelections.length;
            }

            function renderChips() {
              chipList.innerHTML = '';
              if (!conflictSelections.length) {
                chipList.innerHTML = '<span class="conflict-empty">No conflicts added yet.</span>';
                return;
              }
              conflictSelections.forEach(function(c) {
                var chip = document.createElement('span');
                chip.className = 'conflict-chip';
                chip.innerHTML = '⚠ ' + c.name + (c.strength ? ' ' + c.strength : '') + ' <button type="button">×</button>';
                chip.querySelector('button').addEventListener('click', function() {
                  conflictSelections = conflictSelections.filter(function(x) {
                    return x.id !== c.id;
                  });
                  renderChips();
                  refreshHidden();
                });
                chipList.appendChild(chip);
              });
            }
            window.openConflictPicker = function(btn) {
              var wrap = btn.closest('.conflict-picker');
              var menu = wrap.querySelector('.conflict-menu');
              var isOpen = menu.classList.contains('open');
              document.querySelectorAll('.conflict-menu.open').forEach(function(m) {
                m.classList.remove('open');
              });
              if (!isOpen) {
                renderPickerList(menu.querySelector('.conflict-menu-list'), '');
                menu.classList.add('open');
                setTimeout(function() {
                  menu.querySelector('.conflict-menu-search').focus();
                }, 80);
              }
            };

            function renderPickerList(listEl, filter) {
              listEl.innerHTML = '';
              var f = (filter || '').toLowerCase();
              var results = ALL_MEDICINES.filter(function(m) {
                if (conflictSelections.some(function(x) {
                    return x.id === m.id;
                  })) return false;
                if (f && m.name.toLowerCase().indexOf(f) === -1 && (m.company || '').toLowerCase().indexOf(f) === -1) return false;
                return true;
              });
              if (!results.length) {
                listEl.innerHTML = '<div class="conflict-option" style="cursor:default;color:#94a3b8;font-style:italic;">No medicine found.</div>';
                return;
              }
              results.forEach(function(m) {
                var d = document.createElement('div');
                d.className = 'conflict-option';
                d.innerHTML = '<span>' + m.name + (m.strength ? ' ' + m.strength : '') + '</span><small>' + (m.company || '') + '</small>';
                d.addEventListener('click', function() {
                  if (!conflictSelections.some(function(x) {
                      return x.id === m.id;
                    })) {
                    conflictSelections.push({
                      id: m.id,
                      name: m.name,
                      strength: m.strength
                    });
                  }
                  renderChips();
                  refreshHidden();
                  document.querySelectorAll('.conflict-menu.open').forEach(function(mm) {
                    mm.classList.remove('open');
                  });
                });
                listEl.appendChild(d);
              });
            }
            window.filterConflictList = function(inp) {
              var menu = inp.closest('.conflict-menu');
              renderPickerList(menu.querySelector('.conflict-menu-list'), inp.value);
            };
            document.addEventListener('click', function(e) {
              if (!e.target.closest('.conflict-picker')) {
                document.querySelectorAll('.conflict-menu.open').forEach(function(m) {
                  m.classList.remove('open');
                });
              }
            });
            refreshHidden();
            renderChips();
          })();
        </script>

      <?php elseif ($tab === 'stock-expiry'): ?>
        <div class="two-col">
          <section>
            <h2>⚠ Low Stock Items</h2><?php foreach ($lowItems as $x): ?><div class="alert low-bg"><b><?= h($x['medicine_name']) ?></b><span><?= $x['stock_qty'] ?> / <?= $x['reorder_level'] ?></span></div><?php endforeach; ?><?php if (!$lowItems): ?><div class="empty">No low-stock medicines.</div><?php endif; ?>
          </section>
          <section>
            <h2>● Expiring Soon</h2><?php foreach ($expiring as $x): ?><div class="alert expiry-bg"><b><?= h($x['medicine_name']) ?></b><span><?= $x['days_left'] ?> days</span></div><?php endforeach; ?><?php if (!$expiring): ?><div class="empty">No medicines expiring within 90 days.</div><?php endif; ?>
          </section>
        </div>

      <?php elseif ($tab === 'pending-approvals'): ?>
        <div class="section-head">
          <h2>Pharmacist Pending Approvals</h2><span><?= $pendingCount ?> pending</span>
        </div>
        <section class="approval-grid">
          <?php foreach ($pending as $p): ?>
            <article class="approval-card">
              <div class="row">
                <div><b><?= h($p['full_name']) ?></b>
                  <div class="muted"><?= h($p['email']) ?></div>
                </div><span class="pill">Pharmacist</span>
              </div>
              <div class="approval-info">
                <div><b>Pharmacy:</b> <?= h($p['pharmacy_name'] ?: $p['pharmacy_or_company_name']) ?></div>
                <div><b>License:</b> <?= h($p['license_no']) ?></div>
                <div><b>Phone:</b> <?= h($p['phone']) ?></div>
                <div><b>Registered:</b> <?= h(date('d M Y', strtotime($p['registered_at']))) ?></div>
              </div>
              <div class="actions">
                <form method="post" action="actions.php"><input type="hidden" name="action" value="approve_pharmacist"><input type="hidden" name="pharmacist_profile_id" value="<?= $p['pharmacist_profile_id'] ?>"><input type="hidden" name="tab" value="pending-approvals"><button class="approve">Approve</button></form>
                <form method="post" action="actions.php"><input type="hidden" name="action" value="reject_pharmacist"><input type="hidden" name="pharmacist_profile_id" value="<?= $p['pharmacist_profile_id'] ?>"><input type="hidden" name="tab" value="pending-approvals"><button class="reject">Reject</button></form>
              </div>
            </article>
          <?php endforeach; ?>
        </section>
        <?php if (!$pending): ?><div class="empty">There are no pending pharmacist approvals.</div><?php endif; ?>

      <?php elseif ($tab === 'all-users'): ?>
        <div class="section-head">
          <div>
            <h2>All Users</h2><span><?= $totalUsers ?> active accounts</span>
          </div>
          <div class="role-filter"><?php foreach ($roleCounts as $r => $c): ?><a class="<?= $roleFilter === $r ? 'active' : '' ?>" href="?tab=all-users&role=<?= h($r) ?>"><?= ucfirst(strtolower($r)) ?>(<?= $c ?>)</a><?php endforeach; ?></div>
        </div>
        <section class="user-list">
          <?php foreach ($users as $u): $isActive = (int)$u['is_active'] === 1;
            $isPharmacist = strtoupper($u['role']) === 'PHARMACIST';
            $pharmacyLabel = '';
            if ($isPharmacist) {
              $pharmacyLabel = trim((string)($u['linked_pharmacy_name'] ?? ''));
              if ($pharmacyLabel === '') $pharmacyLabel = trim((string)($u['profile_pharmacy_name'] ?? ''));
            }
          ?>
            <article class="user-row<?= $isActive ? '' : ' inactive' ?>">
              <div><b><?= h($u['full_name']) ?></b><span class="pill"><?= h(ucfirst(strtolower($u['role']))) ?></span><?php if (!$isActive): ?><span class="pill pill-inactive">Inactive</span><?php endif; ?><?php if ($isPharmacist && $pharmacyLabel !== ''): ?><span class="pill pill-pharmacy" title="Assigned pharmacy">🏥 <?= h($pharmacyLabel) ?></span><?php endif; ?><div class="muted"><?= h($u['email']) ?></div>
              </div>
              <div class="muted">#<?= $u['user_id'] ?> · <?= h($u['phone']) ?></div>
              <?php if ($u['role'] !== 'ADMIN'): ?>
                <div class="user-actions">
                  <?php if ($isActive): ?><form method="post" action="actions.php" onsubmit="return confirm('Deactivate this user?')"><input type="hidden" name="action" value="deactivate_user"><input type="hidden" name="user_id" value="<?= $u['user_id'] ?>"><input type="hidden" name="tab" value="all-users"><button class="deactivate">Deactivate</button></form><?php else: ?><form method="post" action="actions.php"><input type="hidden" name="action" value="activate_user"><input type="hidden" name="user_id" value="<?= $u['user_id'] ?>"><input type="hidden" name="tab" value="all-users"><button class="activate">Activate</button></form><?php endif; ?>
                  <form method="post" action="actions.php" onsubmit="return confirm('Permanently delete this user?')"><input type="hidden" name="action" value="delete_user"><input type="hidden" name="user_id" value="<?= $u['user_id'] ?>"><input type="hidden" name="tab" value="all-users"><button class="danger">Delete</button></form>
                </div>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </section>
        <?php if (!$users): ?><div class="empty">No users match your filter.</div><?php endif; ?>

      <?php elseif ($tab === 'support'): ?>
        <div class="section-head">
          <h2>Help &amp; Support Requests</h2><span><?= count($support) ?> requests</span>
        </div>
        <section class="support-list">
          <?php foreach ($support as $s):
            $replyCount = (int)($s['reply_count'] ?? 0);
            $replied = $replyCount > 0;
            $senderName = $s['sender_full_name'] ?? 'User';
            $senderRole = $s['sender_role'] ?? 'PATIENT';
          ?>
            <article class="support-card <?= $replied ? 'replied' : 'unreplied' ?>" data-notification-id="<?= (int)$s['notification_id'] ?>">
              <div class="row">
                <b><?= h($s['title']) ?><?php if ($replied): ?><span class="support-replied-badge">✓ Replied</span><?php endif; ?></b>
                <span class="muted"><?= h($s['created_at']) ?></span>
              </div>
              <p><?= h($s['message']) ?></p>
              <div class="support-meta">From: <strong><?= h($senderName) ?></strong> (<?= h($senderRole) ?>)</div>
              <div class="support-actions">
                <?php if ($replied): ?>
                  <button type="button" class="support-reply-btn disabled" disabled>Already Replied</button>
                <?php else: ?>
                  <button type="button" class="support-reply-btn"
                    data-notification-id="<?= (int)$s['notification_id'] ?>"
                    data-sender-name="<?= h($senderName) ?>"
                    data-sender-role="<?= h($senderRole) ?>"
                    data-original-msg="<?= h($s['message']) ?>">↩ Reply</button>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </section>
        <?php if (!$support): ?><div class="empty">No support requests yet.</div><?php endif; ?>

      <?php endif; ?>
    </main>
  </div>

  <!-- ============ REPLY MODAL ============ -->
  <div class="reply-modal-overlay" id="replyModal">
    <div class="reply-modal">
      <h2>Reply to Support Request</h2>
      <div style="font-size:13px;color:#64748b;margin-bottom:8px;">To: <strong id="replySenderName">User</strong></div>
      <div class="orig-msg" id="replyOriginalMsg"></div>
      <form method="post" action="actions.php">
        <input type="hidden" name="action" value="reply_support">
        <input type="hidden" name="notification_id" id="replyNotificationId" value="">
        <input type="hidden" name="tab" value="support">
        <textarea name="reply_message" id="replyMessageBox" placeholder="Write your reply…" maxlength="600" required></textarea>
        <div class="reply-modal-actions">
          <button type="button" class="reply-cancel" id="replyCancelBtn">Cancel</button>
          <button type="submit" class="reply-send">Send Reply</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    (function() {
      var modal = document.getElementById('replyModal');
      if (!modal) return;
      document.addEventListener('click', function(e) {
        var btn = e.target.closest('.support-reply-btn');
        if (!btn || btn.disabled) return;
        document.getElementById('replyNotificationId').value = btn.dataset.notificationId;
        document.getElementById('replySenderName').textContent = btn.dataset.senderName + ' (' + btn.dataset.senderRole + ')';
        document.getElementById('replyOriginalMsg').textContent = btn.dataset.originalMsg;
        document.getElementById('replyMessageBox').value = '';
        modal.classList.add('show');
        setTimeout(function() {
          document.getElementById('replyMessageBox').focus();
        }, 100);
      });
      document.getElementById('replyCancelBtn').addEventListener('click', function() {
        modal.classList.remove('show');
      });
      modal.addEventListener('click', function(e) {
        if (e.target === modal) modal.classList.remove('show');
      });
      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.classList.contains('show')) modal.classList.remove('show');
      });
    })();
  </script>

  <?php if ($tab === 'all-medicine'): ?>
    <script>
      (function() {
        var input = document.getElementById('adminSearchInput');
        var box = document.getElementById('adminSuggestBox');
        var form = document.getElementById('adminSearchForm');
        if (!input || !box || !form) return;
        var items = [],
          active = -1,
          timer = null,
          reqId = 0;

        function hide() {
          box.hidden = true;
          box.innerHTML = '';
          items = [];
          active = -1;
        }

        function pick(label) {
          input.value = label;
          hide();
          form.submit();
        }

        function setActive(i) {
          for (var k = 0; k < box.children.length; k++) box.children[k].classList.toggle('active', k === i);
          active = i;
        }

        function render(list, q) {
          box.innerHTML = '';
          items = list;
          active = -1;
          if (!list.length) {
            box.hidden = true;
            return;
          }
          list.forEach(function(label) {
            var d = document.createElement('div');
            d.className = 'admin-suggest-item';
            var at = label.toLowerCase().indexOf(q.toLowerCase());
            if (at >= 0) {
              d.appendChild(document.createTextNode(label.slice(0, at)));
              var m = document.createElement('mark');
              m.textContent = label.slice(at, at + q.length);
              d.appendChild(m);
              d.appendChild(document.createTextNode(label.slice(at + q.length)));
            } else {
              d.textContent = label;
            }
            d.addEventListener('mousedown', function(ev) {
              ev.preventDefault();
              pick(label);
            });
            box.appendChild(d);
          });
          box.hidden = false;
        }

        function fetchSuggestions() {
          var q = input.value.trim();
          if (q === '') {
            hide();
            return;
          }
          var id = ++reqId;
          fetch('index.php?suggest=1&q=' + encodeURIComponent(q), {
              credentials: 'same-origin'
            })
            .then(function(r) {
              return r.json();
            })
            .then(function(list) {
              if (id === reqId && document.activeElement === input) render(list, q);
            })
            .catch(function() {
              hide();
            });
        }
        input.addEventListener('input', function() {
          clearTimeout(timer);
          timer = setTimeout(fetchSuggestions, 180);
        });
        input.addEventListener('focus', function() {
          if (input.value.trim() !== '') fetchSuggestions();
        });
        input.addEventListener('keydown', function(ev) {
          if (box.hidden) return;
          if (ev.key === 'ArrowDown') {
            ev.preventDefault();
            setActive((active + 1) % items.length);
          } else if (ev.key === 'ArrowUp') {
            ev.preventDefault();
            setActive((active - 1 + items.length) % items.length);
          } else if (ev.key === 'Enter' && active >= 0) {
            ev.preventDefault();
            pick(items[active]);
          } else if (ev.key === 'Escape') {
            hide();
          }
        });
        document.addEventListener('click', function(ev) {
          if (!form.contains(ev.target)) hide();
        });
      })();
    </script>
  <?php endif; ?>
</body>

</html>
<?php
function render_admin_medicine_card(array $m, string $q, bool $low, bool $soon, string $mode): string
{
  global $conn;
  $medId   = (int)$m['medicine_id'];
  $name    = htmlspecialchars((string)$m['medicine_name'], ENT_QUOTES, 'UTF-8');
  $safeQ   = htmlspecialchars($q, ENT_QUOTES, 'UTF-8');
  $img     = medicine_image_html($m);
  $cat     = htmlspecialchars((string)($m['category_name'] ?? 'Uncategorized'), ENT_QUOTES, 'UTF-8');
  $strength = htmlspecialchars((string)($m['strength'] ?? ''), ENT_QUOTES, 'UTF-8');
  $formLbl = htmlspecialchars(medicine_form_label($m['dosage_form'] ?? ''), ENT_QUOTES, 'UTF-8');
  $company = htmlspecialchars((string)($m['company_name'] ?? ''), ENT_QUOTES, 'UTF-8');
  $generic = htmlspecialchars((string)($m['generic_name'] ?? ''), ENT_QUOTES, 'UTF-8');
  $desc    = htmlspecialchars((string)($m['description'] ?: 'No description available.'), ENT_QUOTES, 'UTF-8');
  $expiry  = $m['nearest_expiry'] ? htmlspecialchars(date('M Y', strtotime($m['nearest_expiry'])), ENT_QUOTES, 'UTF-8') : '';
  $restrictedBadge = ((int)$m['is_restricted'] === 1) ? '<span class="restricted-badge">Restricted</span>' : '';

  $conflicts = get_medicine_conflicts($conn, $medId);
  $conflictCount = count($conflicts);
  $payload = [];
  foreach ($conflicts as $c) $payload[] = ['medicine_id' => (int)$c['medicine_id'], 'medicine_name' => (string)$c['medicine_name'], 'strength' => (string)($c['strength'] ?? '')];
  $payloadJson = htmlspecialchars(json_encode($payload), ENT_QUOTES, 'UTF-8');
  $conflictBadge = $conflictCount > 0
    ? '<button type="button" class="edit-conflicts-btn conflict-badge-sm" data-medicine-id="' . $medId . '" data-medicine-name="' . $name . '" data-conflicts="' . $payloadJson . '">⚠️ ' . $conflictCount . ' conflict' . ($conflictCount === 1 ? '' : 's') . '</button>'
    : '<button type="button" class="edit-conflicts-btn" data-medicine-id="' . $medId . '" data-medicine-name="' . $name . '" data-conflicts="[]" style="border:0;background:transparent;color:#64748b;font-size:11px;font-weight:700;cursor:pointer;padding:3px 6px;">+ Add conflict</button>';

  ob_start();
?>
  <div class="mv-med-img-box">
    <?= $img ?>
    <div class="mv-img-tools">
      <form class="mv-img-change" method="post" action="actions.php" enctype="multipart/form-data">
        <input type="hidden" name="action" value="update_medicine_image">
        <input type="hidden" name="medicine_id" value="<?= $medId ?>">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($mode === 'hidden' ? 'hidden' : 'all-medicine', ENT_QUOTES) ?>">
        <input type="hidden" name="q" value="<?= $safeQ ?>">
        <label title="Change image">📷<input type="file" name="medicine_image" accept="image/jpeg,image/png,image/webp,image/gif" hidden onchange="this.form.submit()"></label>
      </form>
      <button type="button" class="mv-img-url" title="Change image URL" onclick="adminSetImageUrl(<?= $medId ?>, '<?= htmlspecialchars($mode === 'hidden' ? 'hidden' : 'all-medicine', ENT_QUOTES) ?>', '<?= $safeQ ?>')">🔗</button>
    </div>
  </div>

  <span class="card-category"><?= $cat ?></span>
  <h3><?= $name ?><small><?= $strength ?></small></h3>
  <div class="card-meta"><?= $formLbl ?> &bull; <?= $company ?></div>
  <div class="badges">
    <?php if ($mode === 'hidden'): ?><span class="inactive-badge">Hidden</span><?php endif; ?>
    <span class="<?= $low ? 'low' : 'stock' ?>"><?= $low ? 'Low stock' : 'In stock' ?></span>
    <?php if ($expiry !== ''): ?><span class="expiry <?= $soon ? 'soon' : '' ?>">Ex. <?= $expiry ?></span><?php endif; ?>
    <?= $restrictedBadge ?>
  </div>
  <div class="card-generic" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
    <span><?= $generic ?></span>
    <span style="margin-left:auto;"><?= $conflictBadge ?></span>
  </div>
  <p class="card-desc"><?= $desc ?></p>
  <div class="card-actions">
    <?php if ($mode === 'active'): ?>
      <form method="post" action="actions.php" onsubmit="return confirm('Hide this medicine?')"><input type="hidden" name="action" value="hide_medicine"><input type="hidden" name="medicine_id" value="<?= $medId ?>"><input type="hidden" name="tab" value="all-medicine"><button class="hide-btn" type="submit">Hidden</button></form>
      <form method="post" action="actions.php" onsubmit="return confirm('Permanently delete?')"><input type="hidden" name="action" value="delete_medicine"><input type="hidden" name="medicine_id" value="<?= $medId ?>"><input type="hidden" name="tab" value="all-medicine"><button class="danger" type="submit">Delete</button></form>
    <?php else: ?>
      <form method="post" action="actions.php"><input type="hidden" name="action" value="show_medicine"><input type="hidden" name="medicine_id" value="<?= $medId ?>"><input type="hidden" name="tab" value="hidden"><button class="add" type="submit">Show</button></form>
      <form method="post" action="actions.php" onsubmit="return confirm('Permanently delete?')"><input type="hidden" name="action" value="delete_medicine"><input type="hidden" name="medicine_id" value="<?= $medId ?>"><input type="hidden" name="tab" value="hidden"><button class="danger" type="submit">Delete</button></form>
    <?php endif; ?>
  </div>
  <script>
    window.adminSetImageUrl = window.adminSetImageUrl || function(medId, tab, q) {
      var url = window.prompt('Paste the medicine image URL (https://...):');
      if (!url) return;
      if (!/^https?:\/\//i.test(url.trim())) {
        alert('URL must start with http:// or https://');
        return;
      }
      var f = document.createElement('form');
      f.method = 'post';
      f.action = 'actions.php';
      [
        ['action', 'update_medicine_image'],
        ['medicine_image_url', url.trim()],
        ['medicine_id', medId],
        ['tab', tab],
        ['q', q]
      ].forEach(function(p) {
        var i = document.createElement('input');
        i.type = 'hidden';
        i.name = p[0];
        i.value = p[1];
        f.appendChild(i);
      });
      document.body.appendChild(f);
      f.submit();
    };
  </script>
<?php
  return (string)ob_get_clean();
}
?>