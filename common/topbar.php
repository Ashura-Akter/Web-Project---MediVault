<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../database/queries.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login/login.php');
    exit;
}

$mvHeaderUserName = $_SESSION['full_name'] ?? 'User';
$mvHeaderRole = strtoupper(trim((string)($_SESSION['role'] ?? 'PATIENT')));
$mvHeaderInitial = strtoupper(substr(trim($mvHeaderUserName), 0, 1));
$mvHeaderRoleLabel = ucfirst(strtolower($mvHeaderRole));
$mvNotifications = [];
$mvUnreadCount = 0;
$mvPharmacyTitle = '';

$mvBrandHref = '../patient/browse.php';
if ($mvHeaderRole === 'PHARMACIST') $mvBrandHref = '../pharmasist/medivaultpharmasist.php?tab=all';
elseif ($mvHeaderRole === 'ADMIN') $mvBrandHref = '../admin/index.php?tab=all-medicine';

if ($mvHeaderRole === 'PHARMACIST') {
    $mvPh = $conn->prepare("SELECT COALESCE(p.pharmacy_name, pp.pharmacy_or_company_name) AS name
                            FROM pharmacist_profiles pp
                            LEFT JOIN pharmacies p ON p.pharmacy_id = pp.pharmacy_id
                            WHERE pp.user_id = ? LIMIT 1");
    $mvPhUid = (int)$_SESSION['user_id'];
    $mvPh->bind_param('i', $mvPhUid);
    $mvPh->execute();
    $mvPhRow = $mvPh->get_result()->fetch_assoc();
    $mvPharmacyTitle = trim((string)($mvPhRow['name'] ?? ''));
}
if ($mvHeaderRole === 'ADMIN') sync_admin_stock_notifications($conn, (int)$_SESSION['user_id']);
if (in_array($mvHeaderRole, ['PATIENT', 'PHARMACIST', 'ADMIN'], true)) {
    $mvNotifications = get_user_notifications($conn, (int)$_SESSION['user_id'], 20, $mvHeaderRole);
    $mvUnreadCount = get_unread_notification_count($conn, (int)$_SESSION['user_id'], $mvHeaderRole);
}
?>

<style id="medivault-universal-topbar">
    * {
        box-sizing: border-box
    }

    html,
    body {
        margin: 0
    }

    .mv-topbar {
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        right: 0 !important;
        height: 62px !important;
        background: #fff !important;
        border-bottom: 1px solid #ddd !important;
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        padding: 0 30px !important;
        z-index: 99999 !important;
        font-family: Arial, Helvetica, sans-serif !important
    }

    .mv-brand {
        display: flex !important;
        align-items: center !important;
        gap: 8px !important;
        text-decoration: none !important;
        color: #111 !important
    }

    .mv-brand-icon {
        width: 30px !important;
        height: 30px !important;
        border-radius: 8px !important;
        background: #078f86 !important;
        color: #fff !important;
        display: grid !important;
        place-items: center !important;
        font-size: 21px !important;
        font-weight: 800 !important;
        line-height: 1 !important
    }

    .mv-brand-name {
        font-size: 21px !important;
        font-weight: 800 !important;
        color: #111 !important;
        line-height: 1 !important
    }

    .mv-brand-sub {
        font-size: 13px !important;
        color: #777 !important;
        font-weight: 400 !important;
        line-height: 1 !important
    }

    .mv-profile-wrap {
        position: relative !important
    }

    .mv-profile-btn {
        border: 0 !important;
        background: transparent !important;
        display: flex !important;
        align-items: center !important;
        gap: 8px !important;
        cursor: pointer !important;
        padding: 0 !important;
        font-family: Arial, Helvetica, sans-serif !important
    }

    .mv-avatar {
        width: 36px !important;
        height: 36px !important;
        border-radius: 50% !important;
        background: #078f86 !important;
        color: #fff !important;
        display: grid !important;
        place-items: center !important;
        font-weight: 700 !important;
        font-size: 13px !important
    }

    .mv-user-text {
        display: flex !important;
        flex-direction: column !important;
        line-height: 1.1 !important;
        min-width: 100px !important
    }

    .mv-user-text strong {
        font-size: 14px !important;
        text-align: left !important;
        color: #111 !important;
        font-weight: 700 !important
    }

    .mv-user-text small {
        font-size: 11px !important;
        text-align: left !important;
        color: #078f86 !important;
        margin-top: 2px !important
    }

    .mv-chevron {
        font-size: 10px !important;
        color: #111 !important;
        margin-left: 0 !important
    }

    .mv-profile-menu {
        display: none;
        position: absolute !important;
        right: 0 !important;
        top: 44px !important;
        width: 200px !important;
        background: #fff !important;
        border: 1px solid #e2e2e2 !important;
        border-radius: 14px !important;
        box-shadow: 0 8px 24px rgba(0, 0, 0, .14) !important;
        padding: 7px !important;
        z-index: 100000 !important;
        font-family: Arial, Helvetica, sans-serif !important
    }

    .mv-profile-menu.show {
        display: block !important
    }

    .mv-profile-menu a {
        display: block !important;
        padding: 11px 13px !important;
        border-radius: 8px !important;
        text-decoration: none !important;
        color: #222 !important;
        font-weight: 600 !important;
        font-size: 13px !important;
        line-height: 1.2 !important
    }

    .mv-profile-menu a:hover {
        background: #f3f8f7 !important;
        color: #078f86 !important
    }

    .mv-profile-menu .mv-logout-menu {
        color: #ef4444 !important
    }

    .mv-profile-menu .mv-logout-menu:hover {
        color: #dc2626 !important;
        background: #fff2f2 !important
    }

    .mv-notification-wrap {
        position: relative !important;
        display: flex !important;
        align-items: center !important
    }

    .mv-notification-btn {
        position: relative !important;
        width: 38px !important;
        height: 38px !important;
        border: 1px solid #e5e7eb !important;
        background: #fff !important;
        border-radius: 50% !important;
        display: grid !important;
        place-items: center !important;
        cursor: pointer !important;
        padding: 0 !important;
        color: #0f172a !important
    }

    .mv-notification-btn:hover {
        background: #f1f5f9 !important
    }

    .mv-notification-btn .mv-bell-icon svg {
        width: 19px !important;
        height: 19px !important;
        display: block !important
    }

    .mv-notification-dot {
        position: absolute !important;
        top: -4px !important;
        right: -4px !important;
        min-width: 18px !important;
        height: 18px !important;
        padding: 0 4px !important;
        border-radius: 10px !important;
        background: #ef4444 !important;
        color: #fff !important;
        border: 2px solid #fff !important;
        font-size: 10px !important;
        font-weight: 700 !important;
        line-height: 14px !important;
        text-align: center !important
    }

    .mv-notification-tooltip {
        display: none !important;
        position: absolute !important;
        top: 44px !important;
        left: 50% !important;
        transform: translateX(-50%) !important;
        background: #333 !important;
        color: #fff !important;
        padding: 5px 8px !important;
        border-radius: 5px !important;
        font-size: 10px !important;
        white-space: nowrap !important;
        z-index: 100003 !important
    }

    .mv-notification-wrap:hover .mv-notification-tooltip {
        display: block !important
    }

    .mv-notification-menu {
        display: none !important;
        position: absolute !important;
        right: 0 !important;
        top: 48px !important;
        width: 340px !important;
        max-width: calc(100vw - 30px) !important;
        background: #fff !important;
        border: 1px solid #e5e7eb !important;
        border-radius: 16px !important;
        box-shadow: 0 12px 32px rgba(15, 23, 42, .18) !important;
        padding: 0 !important;
        z-index: 100002 !important;
        overflow: hidden !important
    }

    .mv-notification-menu.show {
        display: block !important
    }

    .mv-notification-actions {
        display: flex !important;
        justify-content: space-between !important;
        align-items: center !important;
        padding: 12px 14px !important;
        border-bottom: 1px solid #eef0f2 !important
    }

    .mv-notification-actions .mv-notification-title {
        font-size: 15px !important;
        font-weight: 700 !important;
        color: #0f172a !important
    }

    .mv-mark-all {
        font-size: 12px !important;
        color: #078f86 !important;
        text-decoration: none !important;
        font-weight: 700 !important
    }

    .mv-notification-list {
        max-height: 360px !important;
        overflow: auto !important;
        padding: 8px !important
    }

    .mv-notification-item {
        display: block !important;
        text-decoration: none !important;
        background: #fff !important;
        border: 1px solid #f1f3f5 !important;
        border-radius: 12px !important;
        padding: 11px 12px !important;
        margin-bottom: 6px !important;
        color: #111 !important;
        cursor: pointer !important
    }

    .mv-notification-item:hover {
        background: #f8fafb !important
    }

    .mv-notification-item.unread {
        background: #effaf8 !important;
        border-color: #cdece7 !important
    }

    .mv-notification-item strong {
        display: block !important;
        font-size: 13px !important;
        margin-bottom: 4px !important;
        color: #0f172a !important
    }

    .mv-notification-item p {
        margin: 0 !important;
        font-size: 12px !important;
        color: #5b6472 !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        display: -webkit-box !important;
        -webkit-line-clamp: 2 !important;
        -webkit-box-orient: vertical !important
    }

    .mv-notification-time {
        display: block !important;
        font-size: 10px !important;
        color: #94a3b8 !important;
        margin-top: 6px !important
    }

    .mv-no-notification {
        min-height: 110px !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        font-size: 13px !important;
        color: #64748b !important;
        padding: 20px !important
    }

    @media(max-width:650px) {
        .mv-topbar {
            padding: 0 14px !important
        }

        .mv-brand-sub {
            display: none !important
        }

        .mv-user-text {
            min-width: 0 !important
        }

        .mv-user-text strong,
        .mv-user-text small {
            display: none !important
        }
    }
</style>

<header class="mv-topbar">
    <a class="mv-brand" href="<?= htmlspecialchars($mvBrandHref) ?>">
        <span class="mv-brand-icon">+</span>
        <span class="mv-brand-name">MediVault</span>
        <span class="mv-brand-sub">Campus Health Center</span>
    </a>

    <?php if ($mvPharmacyTitle !== ''): ?>
        <div class="mv-pharmacy-title" aria-label="<?= htmlspecialchars($mvPharmacyTitle, ENT_QUOTES) ?>"><span class="mv-ph-text" id="mvPhText" data-name="<?= htmlspecialchars($mvPharmacyTitle, ENT_QUOTES) ?>"><?= htmlspecialchars($mvPharmacyTitle) ?></span><span class="mv-ph-caret" id="mvPhCaret"></span></div>
    <?php endif; ?>

    <div style="display:flex!important;align-items:center!important;gap:8px!important;position:relative!important">
        <?php if (in_array($mvHeaderRole, ['PATIENT', 'PHARMACIST', 'ADMIN'], true)): ?>
            <div class="mv-notification-wrap" id="mvNotificationWrap">
                <button class="mv-notification-btn" id="mvNotificationBtn" type="button" aria-label="Notifications">
                    <span class="mv-bell-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
                            <path d="M13.7 21a2 2 0 0 1-3.4 0" />
                        </svg></span>
                    <?php if ($mvUnreadCount > 0): ?><span class="mv-notification-dot"><?= $mvUnreadCount > 99 ? '99+' : $mvUnreadCount ?></span><?php endif; ?>
                </button>
                <span class="mv-notification-tooltip">Notifications</span>
                <div class="mv-notification-menu" id="mvNotificationMenu">
                    <?php if ($mvNotifications): ?>
                        <div class="mv-notification-actions">
                            <span class="mv-notification-title">Notifications</span>
                            <?php if ($mvUnreadCount > 0): ?>
                                <a href="../common/notification.php?all=1" class="mv-mark-all">Mark all as read</a>
                            <?php endif; ?>
                        </div>
                        <div class="mv-notification-list">
                            <?php foreach ($mvNotifications as $notification):
                                $nid = (int)$notification['notification_id'];
                                $ntitle = (string)$notification['title'];
                                $isSupportReply = ($ntitle === 'Support Reply');
                            ?>
                                <a class="mv-notification-item <?= !(int)$notification['is_read'] ? 'unread' : '' ?>"
                                    href="../common/notification.php?id=<?= $nid ?>"
                                    data-notif-id="<?= $nid ?>"
                                    data-notif-title="<?= htmlspecialchars($ntitle, ENT_QUOTES) ?>"
                                    data-notif-type="<?= htmlspecialchars((string)$notification['notification_type'], ENT_QUOTES) ?>">
                                    <strong><?= htmlspecialchars($ntitle) ?></strong>
                                    <p><?= htmlspecialchars($notification['message']) ?></p>
                                    <span class="mv-notification-time"><?= htmlspecialchars(date('d M Y, h:i A', strtotime($notification['created_at']))) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="mv-no-notification">Currently You don't have any notification</div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="mv-profile-wrap">
            <button class="mv-profile-btn" id="mvProfileBtn" type="button">
                <span class="mv-avatar"><?= htmlspecialchars($mvHeaderInitial) ?></span>
                <span class="mv-user-text">
                    <strong><?= htmlspecialchars($mvHeaderUserName) ?></strong>
                    <small><?= htmlspecialchars($mvHeaderRoleLabel) ?></small>
                </span>
                <span class="mv-chevron">▼</span>
            </button>

            <div class="mv-profile-menu" id="mvProfileMenu">
                <a href="../account/profile.php">👤&nbsp; Profile</a>
                <?php if ($mvHeaderRole === 'PATIENT' || $mvHeaderRole === 'PHARMACIST'): ?>
                    <a href="../admin/help_support.php">❓&nbsp; Help &amp; Support</a>
                <?php endif; ?>
                <a class="mv-logout-menu" href="../login/logout.php">🚪&nbsp; Log Out</a>
            </div>
        </div>
    </div>
</header>

<!-- ============ SUPPORT REPLY POPUP (same-page, no redirect) ============ -->
<div class="mv-support-reply-overlay" id="mvSupportReplyOverlay">
    <div class="mv-support-reply-modal">
        <button type="button" class="mv-support-reply-close" id="mvSupportReplyClose">&times;</button>
        <h2 id="mvSupportReplyTitle">💬 Admin Reply</h2>
        <div class="mv-support-reply-body" id="mvSupportReplyBody">
            <div class="mv-reply-loading">Loading…</div>
        </div>
    </div>
</div>
<style>
    .mv-support-reply-overlay {
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

    .mv-support-reply-overlay.show {
        display: flex
    }

    .mv-support-reply-modal {
        position: relative;
        width: min(520px, 100%);
        max-height: calc(100vh - 40px);
        overflow: auto;
        background: #fff;
        border-radius: 20px;
        padding: 28px 30px;
        box-shadow: 0 24px 60px rgba(15, 23, 42, .35)
    }

    .mv-support-reply-close {
        position: absolute;
        right: 14px;
        top: 12px;
        width: 34px;
        height: 34px;
        border-radius: 50%;
        border: 0;
        background: #f1f5f9;
        color: #334155;
        font-size: 22px;
        line-height: 1;
        cursor: pointer;
        padding: 0
    }

    .mv-support-reply-close:hover {
        background: #e2e8f0
    }

    .mv-support-reply-modal h2 {
        margin: 0 0 16px;
        font-size: 20px;
        font-weight: 800;
        color: #0f172a;
        text-align: center
    }

    .mv-support-reply-body {
        font-size: 14px;
        color: #334155;
        line-height: 1.6
    }

    .mv-support-reply-body .rep-row {
        display: flex;
        justify-content: space-between;
        gap: 14px;
        padding: 8px 0;
        border-bottom: 1px dashed #dbe7e5;
        font-size: 13.5px
    }

    .mv-support-reply-body .rep-row .lbl {
        color: #64748b;
        font-weight: 700;
        flex: 0 0 auto;
        text-transform: uppercase;
        font-size: 11px;
        letter-spacing: .4px;
        padding-top: 2px
    }

    .mv-support-reply-body .rep-row .val {
        color: #0f172a;
        font-weight: 700;
        text-align: right;
        word-break: break-word
    }

    .mv-support-reply-body .rep-message {
        margin-top: 14px;
        background: #f0faf8;
        border: 1px solid #cdece7;
        border-radius: 12px;
        padding: 14px;
        font-size: 14px;
        line-height: 1.6;
        color: #0f172a;
        font-weight: 600;
        white-space: pre-wrap;
        word-break: break-word
    }

    .mv-support-reply-body .rep-original {
        margin-top: 14px;
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
        border-radius: 10px;
        padding: 12px;
        font-size: 13px;
        color: #475569
    }

    .mv-reply-loading {
        text-align: center;
        padding: 20px;
        color: #94a3b8;
        font-size: 14px;
        font-style: italic
    }
</style>

<script>
    /* Pharmacy name typing effect */
    (function() {
        const el = document.getElementById('mvPhText');
        if (!el) return;
        const caret = document.getElementById('mvPhCaret');
        const full = el.dataset.name || '';
        const chars = Array.from(full);
        let i = 0;

        function type() {
            if (i <= chars.length) {
                el.textContent = chars.slice(0, i).join('');
                i++;
                setTimeout(type, 140);
            } else {
                setTimeout(function() {
                    i = 0;
                    type();
                }, 3000);
            }
        }
        el.style.display = 'inline-block';
        el.style.minWidth = '1px';
        type();
    })();

    /* Profile + notification dropdowns */
    (function() {
        const profileBtn = document.getElementById('mvProfileBtn');
        const profileMenu = document.getElementById('mvProfileMenu');
        const notificationBtn = document.getElementById('mvNotificationBtn');
        const notificationMenu = document.getElementById('mvNotificationMenu');
        const notificationWrap = document.getElementById('mvNotificationWrap');

        if (profileBtn && profileMenu) {
            profileBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                const open = profileMenu.classList.toggle('show');
                if (notificationMenu) notificationMenu.classList.remove('show');
                if (notificationWrap) notificationWrap.classList.remove('open');
            });
        }
        if (notificationBtn && notificationMenu && notificationWrap) {
            notificationBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                const open = notificationMenu.classList.toggle('show');
                notificationWrap.classList.toggle('open', open);
                if (profileMenu) profileMenu.classList.remove('show');
            });
        }
        document.addEventListener('click', function(e) {
            if (profileMenu && profileBtn && !profileMenu.contains(e.target) && !profileBtn.contains(e.target)) profileMenu.classList.remove('show');
            if (notificationMenu && notificationBtn && notificationWrap && !notificationMenu.contains(e.target) && !notificationBtn.contains(e.target)) {
                notificationMenu.classList.remove('show');
                notificationWrap.classList.remove('open');
            }
        });
    })();

    /* Support reply intercept — open popup in same page (no redirect) */
    (function() {
        const overlay = document.getElementById('mvSupportReplyOverlay');
        const closeBtn = document.getElementById('mvSupportReplyClose');
        const body = document.getElementById('mvSupportReplyBody');
        if (!overlay) return;

        function closePop() {
            overlay.classList.remove('show');
            document.body.style.overflow = '';
        }
        if (closeBtn) closeBtn.addEventListener('click', closePop);
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) closePop();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && overlay.classList.contains('show')) closePop();
        });

        document.addEventListener('click', function(e) {
            const item = e.target.closest('.mv-notification-item');
            if (!item) return;
            const title = item.dataset.notifTitle || '';
            if (title !== 'Support Reply') return;
            e.preventDefault();

            const nid = item.dataset.notifId;

            /* Remove unread styling */
            item.classList.remove('unread');

            /* Mark as read + fetch reply */
            body.innerHTML = '<div class="mv-reply-loading">Loading…</div>';
            overlay.classList.add('show');
            document.body.style.overflow = 'hidden';

            fetch('../common/notification.php?ajax_mark_read=1&id=' + encodeURIComponent(nid), {
                    credentials: 'same-origin'
                })
                .catch(function() {});
            fetch('../common/get_reply.php?id=' + encodeURIComponent(nid), {
                    credentials: 'same-origin'
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(d) {
                    if (!d || !d.ok) {
                        body.innerHTML = '<div class="mv-reply-loading">Could not load reply.</div>';
                        return;
                    }
                    let html = '';
                    html += '<div class="rep-row"><span class="lbl">From:</span><span class="val">' + escapeHtml(d.admin_name || 'Admin') + '</span></div>';
                    html += '<div class="rep-row"><span class="lbl">Replied at:</span><span class="val">' + escapeHtml(d.created_at_formatted || '') + '</span></div>';
                    if (d.original_message) {
                        html += '<div class="rep-original"><strong>Your original request:</strong><br>' + escapeHtml(d.original_message) + '</div>';
                    }
                    html += '<div class="rep-message">' + escapeHtml(d.reply_message || '') + '</div>';
                    body.innerHTML = html;
                })
                .catch(function() {
                    body.innerHTML = '<div class="mv-reply-loading">Could not load reply.</div>';
                });
        });

        function escapeHtml(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                } [c];
            });
        }
    })();
</script>