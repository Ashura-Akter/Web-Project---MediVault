<?php
session_start();
require_once __DIR__ . '/../database/queries.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login/login.php');
    exit;
}
$userId = (int)$_SESSION['user_id'];
$nid = (int)($_GET['nid'] ?? 0);
$reply = get_support_reply_by_notification($conn, $nid, $userId);
if (!$reply) {
    header('Location: requests.php');
    exit;
}
?>
<?php require_once __DIR__ . '/../common/header.php'; ?>
<div class="modal-overlay" id="replyModalOverlay" style="display:flex;z-index:100001;">
    <div class="modal viewmore-modal accepted" role="dialog" aria-modal="true">
        <h2><span class="view-icon">💬</span>Admin Reply</h2>
        <div class="res-line"><span class="res-label">From:</span> <?= htmlspecialchars($reply['admin_name']) ?></div>
        <div class="res-line"><span class="res-label">Replied at:</span> <?= date('d M Y, h:i A', strtotime($reply['created_at'])) ?></div>
        <?php if (!empty($reply['original_message'])): ?>
            <div class="res-warning"><span class="res-label">Your original request:</span> <?= htmlspecialchars($reply['original_message']) ?></div>
        <?php endif; ?>
        <div class="res-message" style="font-weight:600;color:#0f172a;background:#f0faf8;padding:14px;border-radius:12px;"><?= nl2br(htmlspecialchars($reply['reply_message'])) ?></div>
        <button class="close-x" type="button" id="closeReplyModal" aria-label="Close">&times;</button>
    </div>
</div>
<script>
    (function() {
        const overlay = document.getElementById('replyModalOverlay');
        const close = document.getElementById('closeReplyModal');

        function closeNow() {
            window.location.href = 'requests.php';
        }
        if (close) close.addEventListener('click', closeNow);
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) closeNow();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeNow();
        });
    })();
</script>
<?php require_once __DIR__ . '/../common/footer.php'; ?>