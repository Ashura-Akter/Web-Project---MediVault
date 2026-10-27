<?php
session_start(); require_once __DIR__ . '/../database/queries.php';
if (!isset($_SESSION['user_id'])) { header('Location: ../login/login.php'); exit; }

$approved = get_latest_approved_prescription($conn,(int)$_SESSION['user_id']);
$showApproval = false;

if ($approved && empty($_SESSION['approval_popup_seen'])) {
    $_SESSION['approval_popup_seen'] = true;
    $showApproval = true;
}
?>
<?php require_once __DIR__ . '/../common/header.php'; ?>

<div class="panel"><h2>Restricted Medicine</h2></div>

<?php if(!$approved): ?>
<div class="restricted-box">
<div class="restricted-warning">This medicine requires a doctor's prescription before it can be purchased. Please upload a clear photo or PDF of your prescription. Our licensed pharmacist will review it, and you'll receive a notification once it has been approved.</div>
<form method="post" action="upload_prescription.php" enctype="multipart/form-data">
<div class="file-row"><input type="file" name="prescription" accept=".jpg,.jpeg,.png,.pdf" required><button class="primary-btn">Upload</button></div>
</form>
</div>
<?php else: ?>
<div class="restricted-box">
<div class="message success">Your prescription has been approved. You can now proceed to purchase your restricted medicine.</div>
<a class="primary-btn" style="display:inline-flex;align-items:center;text-decoration:none" href="browse.php?restricted=1">See Your Item</a>
</div>
<?php endif; ?>

<?php if($showApproval): ?>
<div class="modal-overlay"><div class="modal blue"><h2>Your prescription has been verified and approved by our pharmacy.</h2><p>You can now proceed to purchase your restricted medicine.</p><a class="primary-btn" style="display:inline-flex;align-items:center;text-decoration:none;margin-top:12px" href="browse.php?restricted=1">See Your Item</a></div></div>
<script>setTimeout(()=>document.querySelector('.modal-overlay').remove(),3000);</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../common/footer.php'; ?>