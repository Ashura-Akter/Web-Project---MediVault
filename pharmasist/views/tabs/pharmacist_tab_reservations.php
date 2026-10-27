<?php
/**
 * MediVault - Pharmacist module
 * File    : views/tabs/pharmacist_tab_reservations.php
 * Title   : Tab: Reservations (?tab=reservation)
 *
 * FRONTEND (HTML) + BACKEND (PHP + SQL)
 * Open normal reservations with the pickup-deadline picker and Approve / Reject buttons.
 * SQL inside this file.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');
?>
        <?php
        // Active reservation requests for this pharmacist's assigned pharmacy.
        // SQL: normal reservations (prescription_id IS NULL) that are still PENDING / AWAITING_RX.
        // GROUP_CONCAT joins the medicine names of one reservation. {$res_search_sql} = optional search filter.
        $reservation_q = "SELECT
                            r.reservation_id,
                            r.reservation_number,
                            r.status,
                            r.requested_at,
                            r.reserved_for_hours,
                            r.pickup_deadline,
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
                            AND r.prescription_id IS NULL
                            AND r.status IN ('PENDING','AWAITING_RX') {$res_search_sql}
                          GROUP BY r.reservation_id
                          ORDER BY r.requested_at DESC";
        // Bind: pharmacy id + optional search values.
        $reservation_stmt = $conn->prepare($reservation_q);
        $reservation_stmt->bind_param('i' . $res_search_types, $pharmacyId, ...$res_search_params);
        $reservation_stmt->execute();
        $reservation_result = $reservation_stmt->get_result();
        ?>

        <!-- Table of open reservations with the Approve / Reject actions. -->
        <div id="reservationList">
        <?php if ($reservation_result && $reservation_result->num_rows > 0): ?>
            <div class="table-container">
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>Reservation Id</th>
                            <th>Patient Name</th>
                            <th>Email</th>
                            <th>Medicine Name</th>
                            <th style="text-align:center;">Quantity</th>
                            <th>Requested Until</th>
                            <th>Status / Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $reservation_row = 0; ?>
                        <?php while ($reservation = $reservation_result->fetch_assoc()): ?>
                            <tr class="<?= ($reservation_row++ % 2 === 0) ? 'row-light' : 'row-dark' ?>" id="resRow<?= (int)$reservation['reservation_id'] ?>" data-res-id="<?= (int)$reservation['reservation_id'] ?>">
                                <td style="font-weight:700;"><?= htmlspecialchars($reservation['reservation_number']) ?></td>
                                <td style="font-weight:700;"><?= htmlspecialchars($reservation['patient_name']) ?></td>
                                <td style="font-weight:600;word-break:break-all;"><?= htmlspecialchars($reservation['patient_email']) ?></td>
                                <td><div class="med-list-item"><?= htmlspecialchars($reservation['medicines']) ?></div></td>
                                <td style="text-align:center;font-weight:700;">See item quantities</td>
                                <td style="font-weight:700;">
                                    <?= $reservation['pickup_deadline'] ? date('d/m/Y h:i A', strtotime($reservation['pickup_deadline'])) : '-' ?>
                                </td>
                                <td>
                                    <?php /* Form: Approve sends action_type = RESERVED, Reject opens the cancel-reason modal. */ ?>
                                    <form method="POST" class="js-res-form" id="resForm<?= (int)$reservation['reservation_id'] ?>" data-res-id="<?= (int)$reservation['reservation_id'] ?>" style="margin:0;">
                                        <input type="hidden" name="reservation_id" value="<?= (int)$reservation['reservation_id'] ?>">
                                        <?php /* Pickup deadline picker: date + hour + minute + AM/PM write one hidden value 'YYYY-MM-DD HH:MM'. */ ?>
                                        <?php /* Logic: assets/js/pharmacist_deadline_picker.js. The server reads it as pharmacist_pickup_deadline. */ ?>
                                        <div class="mv-deadline-picker">
    <label>Pickup deadline (optional)</label>
    <input type="hidden" name="pharmacist_pickup_deadline" class="mv-deadline-value" value="<?= $reservation['pickup_deadline'] ? date('Y-m-d H:i',strtotime($reservation['pickup_deadline'])) : '' ?>">
    <div class="mv-deadline-row">
        <input type="date" class="mv-deadline-date" min="<?= date('Y-m-d') ?>" value="<?= $reservation['pickup_deadline'] ? date('Y-m-d',strtotime($reservation['pickup_deadline'])) : '' ?>">
        <select class="mv-deadline-hour" aria-label="Hour"><?php for($h=1;$h<=12;$h++): ?><option value="<?= $h ?>"><?= str_pad((string)$h,2,'0',STR_PAD_LEFT) ?></option><?php endfor; ?></select>
        <span>:</span>
        <select class="mv-deadline-minute" aria-label="Minute"><?php for($m=0;$m<60;$m+=5): ?><option value="<?= $m ?>"><?= str_pad((string)$m,2,'0',STR_PAD_LEFT) ?></option><?php endfor; ?></select>
        <button type="button" class="mv-ampm" data-value="AM">AM</button>
        <button type="button" class="mv-ampm" data-value="PM">PM</button>
    </div>
</div><div class="res-action-btn-group">
                                            <button type="submit" name="action_type" value="RESERVED" class="btn-reserve-pill js-reserve-btn">Approve</button>
                                            <button type="button" class="btn-cancel-pill js-res-cancel-btn" data-res-id="<?= (int)$reservation['reservation_id'] ?>">Reject</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <!-- Empty state. -->
            <div class="pending-card reservation-empty" style="text-align:center;color:#64748b;font-weight:600;">
                No active reservation requests.
            </div>
        <?php endif; ?>
        </div>

        <!-- ============================================================
             Local cancel-reason modal (own copy, so we can control the flow)
             ============================================================ -->
        <div class="modal-overlay" id="resCancelModal" style="display:none;">
            <div class="modal-card">
                <div class="modal-title" style="color:#000;">
                    <span style="color:#ef4444;">✕</span> Cancel Reservation
                </div>

                <form method="POST" id="resCancelForm">
                    <input type="hidden" name="reservation_id" value="">
                    <div class="modal-description" style="margin-bottom: 12px;">
                        Please select a reason for cancelling this reservation.
                    </div>

                    <div class="reason-row">
                        <span>Please Select Reason:</span>
                        <select name="cancel_reason" id="res_cancel_reason" class="reason-select" required>
                            <option value="OUT_OF_STOCK" selected>Out of Stock</option>
                            <option value="DRUG_INTERACTION_DETECTED">Drug Interaction Detected</option>
                            <option value="PRESCRIPTION_REQUIRED">Prescription Required</option>
                            <option value="PRESCRIPTION_NOT_VALID">Prescription Not Valid</option>
                            <option value="DUPLICATE_RESERVATION">Duplicate Reservation</option>
                            <option value="OTHER">Others</option>
                        </select>
                    </div>

                    <div class="modal-input-group" id="resCancelOtherBox" style="display:none;margin-top:14px;">
                        <label for="resCancelOtherNote">Other reason</label>
                        <textarea name="cancellation_note" id="resCancelOtherNote" class="modal-input" rows="3" placeholder="Write the cancellation reason..."></textarea>
                    </div>

                    <div style="margin-top: 24px; display: flex; justify-content: center; gap: 10px;">
                        <button type="button" class="btn-modal-cancel" onclick="closeResCancelModal()">Back</button>
                        <button type="submit" class="btn-modal-action btn-modal-danger">Confirm Cancellation</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Approve success animation overlay -->
        <div class="approve-overlay" id="approveOverlayRes" aria-hidden="true">
            <div class="approve-anim-card">
                <svg class="approve-check" viewBox="0 0 72 72" aria-hidden="true">
                    <circle class="approve-check-circle" cx="36" cy="36" r="30" />
                    <path class="approve-check-mark" d="M22 37 L32 47 L52 27" />
                </svg>
                <div class="approve-anim-text">Approved</div>
                <div class="approve-anim-sub">Processing reservation…</div>
            </div>
        </div>

        <!-- Reject warning animation overlay -->
        <div class="reject-overlay" id="rejectOverlayRes" aria-hidden="true">
            <div class="reject-anim-card">
                <svg class="reject-x" viewBox="0 0 72 72" aria-hidden="true">
                    <circle class="reject-x-circle" cx="36" cy="36" r="30" />
                    <path class="reject-x-mark1" d="M26 26 L46 46" />
                    <path class="reject-x-mark2" d="M46 26 L26 46" />
                </svg>
                <div class="reject-anim-text" id="rejectResAnimText">Rejected</div>
                <div class="reject-anim-sub" id="rejectResAnimSub">Applying reason…</div>
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
        .approve-overlay.is-open { display: flex; opacity: 1; }
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
        .approve-check { width: 96px; height: 96px; display: block; margin: 0 auto 14px; }
        .approve-check-circle {
            fill: none; stroke: #10b981; stroke-width: 3; stroke-linecap: round;
            stroke-dasharray: 189; stroke-dashoffset: 189;
            animation: approveCircle 0.55s ease-out forwards;
        }
        .approve-check-mark {
            fill: none; stroke: #10b981; stroke-width: 5; stroke-linecap: round; stroke-linejoin: round;
            stroke-dasharray: 48; stroke-dashoffset: 48;
            animation: approveMark 0.4s 0.5s ease-out forwards;
        }
        @keyframes approveCircle { to { stroke-dashoffset: 0; } }
        @keyframes approveMark   { to { stroke-dashoffset: 0; } }
        .approve-anim-text { font-size: 22px; font-weight: 800; color: #065f46; letter-spacing: 0.2px; }
        .approve-anim-sub  { font-size: 13px; color: #64748b; font-weight: 600; margin-top: 4px; }

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
        .reject-overlay.is-open { display: flex; opacity: 1; }
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
        .reject-x { width: 96px; height: 96px; display: block; margin: 0 auto 14px; }
        .reject-x-circle {
            fill: none; stroke: #ef4444; stroke-width: 3; stroke-linecap: round;
            stroke-dasharray: 189; stroke-dashoffset: 189;
            animation: rejectCircle 0.55s ease-out forwards;
        }
        .reject-x-mark1, .reject-x-mark2 {
            fill: none; stroke: #ef4444; stroke-width: 5; stroke-linecap: round; stroke-linejoin: round;
            stroke-dasharray: 30; stroke-dashoffset: 30;
        }
        .reject-x-mark1 { animation: rejectMark 0.28s 0.5s ease-out forwards; }
        .reject-x-mark2 { animation: rejectMark 0.28s 0.72s ease-out forwards; }
        @keyframes rejectCircle { to { stroke-dashoffset: 0; } }
        @keyframes rejectMark   { to { stroke-dashoffset: 0; } }
        .reject-anim-text { font-size: 22px; font-weight: 800; color: #b91c1c; letter-spacing: 0.2px; }
        .reject-anim-sub  { font-size: 13px; color: #64748b; font-weight: 600; margin-top: 4px; }

        /* Dim the row while its action is playing. */
        .history-table tr.is-approving,
        .history-table tr.is-rejecting {
            transition: opacity 0.25s ease, transform 0.25s ease;
            opacity: 0.55;
            transform: scale(0.998);
            pointer-events: none;
        }

        /* Smooth removal of a table row once the server accepted the change. */
        .history-table tr.is-removing {
            transition: opacity 0.35s ease, transform 0.35s ease;
            opacity: 0;
            transform: translateX(40px) scale(0.98);
        }

        /* Red "Confirm Cancellation" button (matches the reject colour). */
        .btn-modal-danger {
            background: #ef4444 !important;
            color: #ffffff !important;
        }
        .btn-modal-danger:hover {
            background: #dc2626 !important;
        }
        </style>

        <script>
        /* Approve + Reject flow for the Reservations tab.

           Approve:
             Click "Approve" -> green check animation -> fetch POST (action_type=RESERVED)
             -> row slides out and is removed.

           Reject:
             Click "Reject" -> local cancel-reason modal opens (no animation).
             Choose reason, click "Confirm Cancellation" (red button)
             -> red X animation -> fetch POST (confirm_cancellation)
             -> modal closes, row slides out and is removed.
        */
        (function () {
            var approveOverlay  = document.getElementById('approveOverlayRes');
            var rejectOverlay   = document.getElementById('rejectOverlayRes');
            var reservationList = document.getElementById('reservationList');
            var cancelModal     = document.getElementById('resCancelModal');
            var cancelForm      = document.getElementById('resCancelForm');
            var cancelReason    = document.getElementById('res_cancel_reason');
            var cancelOtherBox  = document.getElementById('resCancelOtherBox');
            var cancelOtherNote = document.getElementById('resCancelOtherNote');

            function showOverlay(el) { if (el) { el.classList.add('is-open'); el.setAttribute('aria-hidden', 'false'); } }
            function hideOverlay(el) { if (el) { el.classList.remove('is-open'); el.setAttribute('aria-hidden', 'true'); } }

            function removeRow(row) {
                if (!row) return;
                row.classList.add('is-removing');
                window.setTimeout(function () {
                    row.remove();
                    if (reservationList) {
                        var tbody = reservationList.querySelector('tbody');
                        if (tbody && !tbody.querySelector('tr[data-res-id]')) {
                            reservationList.innerHTML =
                                '<div class="pending-card reservation-empty" style="text-align:center;color:#64748b;font-weight:600;">' +
                                'No active reservation requests.' +
                                '</div>';
                        }
                    }
                }, 380);
            }

            /* ---------- APPROVE ---------- */
            document.querySelectorAll('form.js-res-form').forEach(function (form) {
                form.addEventListener('submit', function (e) {
                    var submitter = e.submitter || document.activeElement;
                    if (!submitter || !submitter.classList.contains('js-reserve-btn')) return;

                    e.preventDefault();
                    var row = form.closest('tr');
                    if (row) row.classList.add('is-approving');
                    showOverlay(approveOverlay);

                    window.setTimeout(function () {
                        hideOverlay(approveOverlay);
                        if (row) row.classList.remove('is-approving');

                        var fd = new FormData(form);
                        fd.set('action_type', 'RESERVED');
                        fd.set('ajax_action', '1');

                        fetch(window.location.pathname + window.location.search, {
                            method: 'POST',
                            body: fd,
                            credentials: 'same-origin',
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        })
                        .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
                        .then(function (data) {
                            if (data && data.ok) removeRow(row);
                            else window.location.reload();
                        })
                        .catch(function () { window.location.reload(); });
                    }, 1050);
                });
            });

            /* ---------- REJECT (step 1: open the reason modal) ---------- */
            document.querySelectorAll('.js-res-cancel-btn').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    var resId = btn.getAttribute('data-res-id');
                    if (!resId || !cancelModal || !cancelForm) return;

                    cancelForm.querySelector('input[name="reservation_id"]').value = resId;
                    // Reset the form each time.
                    if (cancelReason) cancelReason.selectedIndex = 0;
                    if (cancelOtherBox) cancelOtherBox.style.display = 'none';
                    if (cancelOtherNote) { cancelOtherNote.value = ''; cancelOtherNote.required = false; }

                    cancelModal.style.display = 'flex';
                });
            });

            /* Show/hide "Other reason" textarea when the select changes. */
            if (cancelReason) {
                cancelReason.addEventListener('change', function () {
                    var isOther = cancelReason.value === 'OTHER';
                    if (cancelOtherBox) cancelOtherBox.style.display = isOther ? 'block' : 'none';
                    if (cancelOtherNote) cancelOtherNote.required = isOther;
                    if (!isOther && cancelOtherNote) cancelOtherNote.value = '';
                });
            }

            /* Exposed so the Back button can call it. */
            window.closeResCancelModal = function () {
                if (cancelModal) cancelModal.style.display = 'none';
            };

            /* ---------- REJECT (step 2: animation then submit) ---------- */
            if (cancelForm) {
                cancelForm.addEventListener('submit', function (e) {
                    e.preventDefault();

                    var resId = parseInt(cancelForm.querySelector('input[name="reservation_id"]').value || '0', 10);
                    if (!resId) return;

                    // Close the reason modal so the animation is the focus.
                    if (cancelModal) cancelModal.style.display = 'none';

                    var row = document.querySelector('tr[data-res-id="' + resId + '"]');
                    if (row) row.classList.add('is-rejecting');

                    var tEl = document.getElementById('rejectResAnimText');
                    var sEl = document.getElementById('rejectResAnimSub');
                    if (tEl) tEl.textContent = 'Rejected';
                    if (sEl) sEl.textContent = 'Applying reason…';

                    showOverlay(rejectOverlay);

                    window.setTimeout(function () {
                        hideOverlay(rejectOverlay);
                        if (row) row.classList.remove('is-rejecting');

                        var fd = new FormData(cancelForm);
                        fd.set('confirm_cancellation', '1');
                        fd.set('ajax_action', '1');

                        fetch(window.location.pathname + window.location.search, {
                            method: 'POST',
                            body: fd,
                            credentials: 'same-origin',
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        })
                        .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
                        .then(function (data) {
                            if (data && data.ok) removeRow(row);
                            else window.location.reload();
                        })
                        .catch(function () { window.location.reload(); });
                    }, 1050);
                });
            }
        })();
        </script>