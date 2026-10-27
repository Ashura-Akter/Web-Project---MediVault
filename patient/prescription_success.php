<?php
session_start();
require_once __DIR__ . '/../database/queries.php';
if (!isset($_SESSION['user_id'])) { header('Location: ../login/login.php'); exit; }
$show = $_SESSION['upload_popup'] ?? false;
unset($_SESSION['upload_popup']);
$error = $_SESSION['upload_error'] ?? '';
unset($_SESSION['upload_error']);
$returnUrl = 'requests.php';
unset($_SESSION['upload_return_url']);
if (!preg_match('/^(?:[A-Za-z0-9_-]+\.php)(?:\?[^#]*)?$/', $returnUrl)) {
    $returnUrl = 'browse.php?view=all';
}
?>
<?php require_once __DIR__ . '/../common/header.php'; ?>
<div class="panel"><h2>Prescription</h2></div>
<div class="restricted-box"><p>Your prescription request is waiting for pharmacist verification.</p></div>
<?php if($show): ?>
<div class="modal-overlay" id="rxSuccessOverlay" style="z-index:100001;">
    <div class="modal">
        <h2>Prescription Uploaded Successfully.</h2>
        <p>Please wait Until verification completed.</p>
        <div class="check">✓</div>
    </div>
</div>
<script>
(function(){
    const returnUrl = <?= json_encode($returnUrl) ?>;
    setTimeout(function(){ window.location.href = returnUrl; }, 3000);
})();
</script>
<?php endif; ?>
<?php if($error): ?><div class="message"><?=htmlspecialchars($error)?></div><?php endif; ?>
<?php require_once __DIR__ . '/../common/footer.php'; ?>