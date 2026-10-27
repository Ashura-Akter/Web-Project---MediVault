<?php
/**
 * MediVault - Pharmacist module
 * File    : views/tabs/pharmacist_tab_pending.php
 * Title   : Tab: Pending (?tab=pending)
 *
 * FRONTEND (HTML) + BACKEND (PHP + SQL)
 * Restricted-medicine (prescription) requests waiting for approval or rejection.
 * SQL inside this file.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');
?>
        <?php
        // Load all pending requests from the database.
        // SQL: restricted-medicine requests (prescription_id IS NOT NULL) in status PENDING / AWAITING_RX.
        //   reservations r -> users u (patient) -> reservation_items ri -> medicines m
        //   GROUP_CONCAT joins all medicine names of one request into one text.
        //   {$res_search_sql} is the optional search filter from includes/pharmacist_page_state.php. Newest first.
        $pending_q = "SELECT
                        r.reservation_id,
                        r.reservation_number,
                        r.status,
                        r.requested_at,
                        r.prescription_id,
                        u.full_name AS patient_name,
                        u.email AS patient_email,
                        GROUP_CONCAT(
                            m.medicine_name
                            ORDER BY m.medicine_name SEPARATOR ', '
                        ) AS medicines
                      FROM reservations r
                      JOIN users u ON u.user_id = r.patient_id
                      JOIN reservation_items ri ON ri.reservation_id = r.reservation_id
                      JOIN medicines m ON m.medicine_id = ri.medicine_id
                      WHERE r.pharmacy_id = ?
                        AND r.prescription_id IS NOT NULL
                        AND r.status IN ('PENDING','AWAITING_RX') {$res_search_sql}
                      GROUP BY r.reservation_id
                      ORDER BY r.requested_at DESC";
        // Bind: 'i' = pharmacy id, followed by the search types ('sss' when searching).
        $pending_stmt = $conn->prepare($pending_q);
        $pending_stmt->bind_param('i' . $res_search_types, $pharmacyId, ...$res_search_params);
        $pending_stmt->execute();
        $pending_result = $pending_stmt->get_result();
        ?>

        <!-- Wrapper so JS can detect when the panel becomes empty and show the empty state. -->
        <div id="pendingList">
            <?php if ($pending_result && $pending_result->num_rows > 0): ?>
                <?php while ($pending = $pending_result->fetch_assoc()): ?>
                    <div class="pending-card" id="pendingCard<?= (int)$pending['reservation_id'] ?>" data-res-id="<?= (int)$pending['reservation_id'] ?>">
                        <?php /* Form posts to this page: Approve = action_type APPROVED, Reject opens the reject modal.
                                 Both actions are also handled via AJAX so the card can be removed
                                 from the panel without a full page reload. */ ?>
                        <form method="POST" class="js-approve-form" data-res-id="<?= (int)$pending['reservation_id'] ?>">
                            <input type="hidden" name="reservation_id" value="<?= (int)$pending['reservation_id'] ?>">
                            <div class="pending-header">
                                <div>
                                    <div style="font-size: 15px; color: #4b5563;">
                                        <?= htmlspecialchars($pending['reservation_number']) ?>
                                    </div>
                                    <div style="font-size: 15px; color: #4b5563;">
                                        <?= date('d F Y', strtotime($pending['requested_at'])) ?>
                                    </div>
                                </div>
                                <span class="badge-awaiting">
                                    <?= htmlspecialchars($pending['status'] === 'AWAITING_RX' ? 'Awaiting Rx' : 'Pending') ?>
                                </span>
                            </div>

                            <div style="font-size: 16px; font-weight: 700; margin-bottom: 8px;">
                                <?= htmlspecialchars($pending['patient_name']) ?>
                            </div>
                            <div style="font-size: 13px; color: #64748b; margin-bottom: 14px;">
                                <?= htmlspecialchars($pending['patient_email']) ?>
                            </div>

                            <div style="font-size: 15px; font-weight: 700; margin-bottom: 16px; line-height: 1.7;">
                                <?= htmlspecialchars($pending['medicines']) ?>
                            </div>

                            <?php /* Yellow banner: the patient uploaded a prescription. */ ?>
                            <div class="alert-banner">Prescription uploaded by patient</div>

                            <div style="margin-bottom:14px;">
                                <?php /* Opens the prescription viewer overlay (assets/js/pharmacist_rx_viewer.js loads prescription_view.php). */ ?>
                                <button type="button" class="btn-action-green" style="display:inline-flex;align-items:center;justify-content:center;padding:8px 14px;border:0;cursor:pointer;" onclick="openPrescriptionViewer(<?= (int)$pending['reservation_id'] ?>)">View Prescription</button>
                            </div>

                            <?php /* Optional note; it is saved in prescriptions.pharmacist_note when the request is approved. */ ?>
                            <input type="text" name="pharmacist_note" class="note-input" placeholder="Pharmacist note (optional).....">

                            <div class="action-buttons">
                                <button type="submit" name="action_type" value="APPROVED" class="btn-approve js-approve-btn">Approve</button>
                                <button type="button" class="btn-reject js-reject-btn" data-res-id="<?= (int)$pending['reservation_id'] ?>">Reject</button>
                            </div>
                        </form>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <!-- Empty state. -->
                <div class="pending-card pending-empty" style="text-align:center; color:#64748b; font-weight:600;">
                    No pending reservation requests.
                </div>
            <?php endif; ?>
        </div>

        <!-- Approve success animation overlay -->
        <div class="approve-overlay" id="approveOverlay" aria-hidden="true">
            <div class="approve-anim-card">
                <svg class="approve-check" viewBox="0 0 72 72" aria-hidden="true">
                    <circle class="approve-check-circle" cx="36" cy="36" r="30" />
                    <path class="approve-check-mark" d="M22 37 L32 47 L52 27" />
                </svg>
                <div class="approve-anim-text">Approved</div>
                <div class="approve-anim-sub">Processing request…</div>
            </div>
        </div>

        <!-- Reject warning animation overlay (only plays after the reason is confirmed) -->
        <div class="reject-overlay" id="rejectOverlay" aria-hidden="true">
            <div class="reject-anim-card">
                <svg class="reject-x" viewBox="0 0 72 72" aria-hidden="true">
                    <circle class="reject-x-circle" cx="36" cy="36" r="30" />
                    <path class="reject-x-mark1" d="M26 26 L46 46" />
                    <path class="reject-x-mark2" d="M46 26 L26 46" />
                </svg>
                <div class="reject-anim-text" id="rejectAnimText">Rejected</div>
                <div class="reject-anim-sub" id="rejectAnimSub">Applying reason…</div>
            </div>
        </div>

        <style>
        /* ---- Approve animation styles ---- */
        .approve-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(3px);
            -webkit-backdrop-filter: blur(3px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2147483600;
            opacity: 0;
            transition: opacity 0.25s ease;
        }
        .approve-overlay.is-open {
            display: flex;
            opacity: 1;
        }
        .approve-anim-card {
            background: #ffffff;
            border-radius: 22px;
            padding: 34px 42px 30px;
            text-align: center;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.28);
            transform: scale(0.85);
            opacity: 0;
            animation: approvePop 0.45s cubic-bezier(0.22, 1, 0.36, 1) forwards;
        }
        @keyframes approvePop {
            0%   { transform: scale(0.85); opacity: 0; }
            60%  { transform: scale(1.04); opacity: 1; }
            100% { transform: scale(1);    opacity: 1; }
        }
        .approve-check {
            width: 96px;
            height: 96px;
            display: block;
            margin: 0 auto 14px;
        }
        .approve-check-circle {
            fill: none;
            stroke: #10b981;
            stroke-width: 3;
            stroke-linecap: round;
            stroke-dasharray: 189;
            stroke-dashoffset: 189;
            animation: approveCircle 0.55s ease-out forwards;
        }
        .approve-check-mark {
            fill: none;
            stroke: #10b981;
            stroke-width: 5;
            stroke-linecap: round;
            stroke-linejoin: round;
            stroke-dasharray: 48;
            stroke-dashoffset: 48;
            animation: approveMark 0.4s 0.5s ease-out forwards;
        }
        @keyframes approveCircle {
            to { stroke-dashoffset: 0; }
        }
        @keyframes approveMark {
            to { stroke-dashoffset: 0; }
        }
        .approve-anim-text {
            font-size: 22px;
            font-weight: 800;
            color: #065f46;
            letter-spacing: 0.2px;
        }
        .approve-anim-sub {
            font-size: 13px;
            color: #64748b;
            font-weight: 600;
            margin-top: 4px;
        }

        /* ---- Reject animation styles ---- */
        .reject-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(3px);
            -webkit-backdrop-filter: blur(3px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2147483600;
            opacity: 0;
            transition: opacity 0.25s ease;
        }
        .reject-overlay.is-open {
            display: flex;
            opacity: 1;
        }
        .reject-anim-card {
            background: #ffffff;
            border-radius: 22px;
            padding: 34px 42px 30px;
            text-align: center;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.28);
            transform: scale(0.85);
            opacity: 0;
            animation: rejectPop 0.45s cubic-bezier(0.22, 1, 0.36, 1) forwards;
        }
        @keyframes rejectPop {
            0%   { transform: scale(0.85); opacity: 0; }
            60%  { transform: scale(1.04); opacity: 1; }
            100% { transform: scale(1);    opacity: 1; }
        }
        .reject-x {
            width: 96px;
            height: 96px;
            display: block;
            margin: 0 auto 14px;
        }
        .reject-x-circle {
            fill: none;
            stroke: #ef4444;
            stroke-width: 3;
            stroke-linecap: round;
            stroke-dasharray: 189;
            stroke-dashoffset: 189;
            animation: rejectCircle 0.55s ease-out forwards;
        }
        .reject-x-mark1,
        .reject-x-mark2 {
            fill: none;
            stroke: #ef4444;
            stroke-width: 5;
            stroke-linecap: round;
            stroke-linejoin: round;
            stroke-dasharray: 30;
            stroke-dashoffset: 30;
        }
        .reject-x-mark1 { animation: rejectMark 0.28s 0.5s ease-out forwards; }
        .reject-x-mark2 { animation: rejectMark 0.28s 0.72s ease-out forwards; }
        @keyframes rejectCircle {
            to { stroke-dashoffset: 0; }
        }
        @keyframes rejectMark {
            to { stroke-dashoffset: 0; }
        }
        .reject-anim-text {
            font-size: 22px;
            font-weight: 800;
            color: #b91c1c;
            letter-spacing: 0.2px;
        }
        .reject-anim-sub {
            font-size: 13px;
            color: #64748b;
            font-weight: 600;
            margin-top: 4px;
        }

        /* Dim the specific card while its action is playing. */
        .pending-card.is-approving,
        .pending-card.is-rejecting {
            transition: opacity 0.25s ease, transform 0.25s ease;
            opacity: 0.55;
            transform: scale(0.995);
            pointer-events: none;
        }

        /* Smooth removal of a card once the server accepted the change. */
        .pending-card.is-removing {
            transition: opacity 0.35s ease, transform 0.35s ease, max-height 0.35s ease, margin 0.35s ease, padding 0.35s ease;
            opacity: 0;
            transform: translateX(40px) scale(0.98);
            max-height: 0 !important;
            margin: 0 !important;
            padding-top: 0 !important;
            padding-bottom: 0 !important;
            overflow: hidden;
        }
        </style>

        <script>
        /* Approve + Reject flow with live panel updates.

           1. APPROVE
              - Click "Approve" -> green check animation plays.
              - After animation, the form is submitted via fetch() as normal POST
                but with a flag so the server knows to reply JSON instead of redirecting.
              - On success the card slides out and is removed from the panel.

           2. REJECT
              - Click "Reject" -> reason modal opens immediately. NO animation here.
              - User picks a reason and clicks "Confirm Rejection".
              - Red X animation plays once.
              - Form is submitted via fetch(). On success the card slides out.
        */
        (function () {
            var approveOverlay = document.getElementById('approveOverlay');
            var rejectOverlay  = document.getElementById('rejectOverlay');
            var pendingList    = document.getElementById('pendingList');

            function showOverlay(el) {
                if (!el) return;
                el.classList.add('is-open');
                el.setAttribute('aria-hidden', 'false');
            }
            function hideOverlay(el) {
                if (!el) return;
                el.classList.remove('is-open');
                el.setAttribute('aria-hidden', 'true');
            }

            function removeCard(card, resId) {
                if (!card) return;
                card.classList.add('is-removing');
                window.setTimeout(function () {
                    card.remove();
                    // Show empty state if no more pending cards exist.
                    if (pendingList && !pendingList.querySelector('.pending-card[data-res-id]')) {
                        if (!pendingList.querySelector('.pending-empty')) {
                            var empty = document.createElement('div');
                            empty.className = 'pending-card pending-empty';
                            empty.setAttribute('style', 'text-align:center; color:#64748b; font-weight:600;');
                            empty.textContent = 'No pending reservation requests.';
                            pendingList.appendChild(empty);
                        }
                    }
                }, 380);
            }

            /* ---------- APPROVE ---------- */
            if (approveOverlay) {
                document.querySelectorAll('form.js-approve-form').forEach(function (form) {
                    form.addEventListener('submit', function (e) {
                        var submitter = e.submitter || document.activeElement;
                        if (!submitter || !submitter.classList.contains('js-approve-btn')) {
                            return;
                        }
                        e.preventDefault();
                        var card = form.closest('.pending-card');
                        if (card) card.classList.add('is-approving');
                        showOverlay(approveOverlay);

                        // Total animation ≈ 1.05s, then submit and remove the card.
                        window.setTimeout(function () {
                            hideOverlay(approveOverlay);
                            if (card) card.classList.remove('is-approving');

                            var fd = new FormData(form);
                            fd.set('action_type', 'APPROVED');
                            fd.set('ajax_action', '1');

                            fetch(window.location.pathname + window.location.search, {
                                method: 'POST',
                                body: fd,
                                credentials: 'same-origin',
                                headers: { 'X-Requested-With': 'XMLHttpRequest' }
                            })
                            .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
                            .then(function (data) {
                                if (data && data.ok) {
                                    removeCard(card, form.getAttribute('data-res-id'));
                                } else {
                                    window.location.reload();
                                }
                            })
                            .catch(function () {
                                window.location.reload();
                            });
                        }, 1050);
                    });
                });
            }

            /* ---------- REJECT (step 1: just open the modal, no animation) ---------- */
            document.querySelectorAll('.js-reject-btn').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    var resId = btn.getAttribute('data-res-id');
                    var form = btn.closest('form');
                    if (!resId) return;
                    if (typeof openRejectModal === 'function') {
                        openRejectModal(parseInt(resId, 10), form);
                    }
                });
            });

            /* ---------- REJECT (step 2: after reason chosen, play animation, then submit) ----------
               The global openRejectModal() builds its own <form method="POST"> inside
               the reject modal. We intercept that form's submit here so we can play
               the animation first and then remove the card after the server responds.
            */
            var rejectModal = document.getElementById('rejectModal');
            if (rejectModal) {
                var rejectForm = rejectModal.querySelector('form');
                if (rejectForm) {
                    rejectForm.addEventListener('submit', function (e) {
                        // Only intercept once the reason has been chosen.
                        var reasonSel = rejectForm.querySelector('#reject_reason');
                        if (reasonSel && !reasonSel.value) {
                            return; // native validation will handle it
                        }
                        e.preventDefault();

                        var resId = parseInt(rejectForm.querySelector('input[name="reservation_id"]').value || '0', 10);
                        if (!resId) { rejectForm.submit(); return; }

                        var card = document.querySelector('.pending-card[data-res-id="' + resId + '"]');

                        var tEl = document.getElementById('rejectAnimText');
                        var sEl = document.getElementById('rejectAnimSub');
                        if (tEl) tEl.textContent = 'Rejected';
                        if (sEl) sEl.textContent = 'Applying reason…';

                        if (card) card.classList.add('is-rejecting');
                        showOverlay(rejectOverlay);

                        window.setTimeout(function () {
                            hideOverlay(rejectOverlay);
                            if (card) card.classList.remove('is-rejecting');

                            // Build the final POST body from the reject modal form.
                            var fd = new FormData(rejectForm);
                            fd.set('action_type', 'REJECTED');
                            fd.set('ajax_action', '1');

                            fetch(window.location.pathname + window.location.search, {
                                method: 'POST',
                                body: fd,
                                credentials: 'same-origin',
                                headers: { 'X-Requested-With': 'XMLHttpRequest' }
                            })
                            .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
                            .then(function (data) {
                                // Close the reject modal either way.
                                if (typeof closeRejectModal === 'function') closeRejectModal();
                                if (data && data.ok) {
                                    removeCard(card, resId);
                                } else {
                                    window.location.reload();
                                }
                            })
                            .catch(function () {
                                if (typeof closeRejectModal === 'function') closeRejectModal();
                                window.location.reload();
                            });
                        }, 1050);
                    });
                }
            }
        })();
        </script>