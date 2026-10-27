<?php
/**
 * MediVault - Pharmacist module
 * File    : views/modals/pharmacist_modal_reject_request.php
 * Title   : Modal: reject request (reason required)
 *
 * FRONTEND (HTML)
 * Opened from the Pending tab. Posts action_type = REJECTED to pharmacist_post_reservation_actions.php.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');
?>
<!-- REJECT REQUEST MODAL (reason required) -->
<!-- Hidden by default. Opened by openRejectModal() from the Pending tab. -->
<div class="modal-overlay" id="rejectModal" style="display: none;">
    <div class="modal-card">
        <div class="modal-title" style="color:#000;">
            <span style="color: #ef4444;">❌</span> Reject Request
        </div>
        <!-- Posts action_type = REJECTED to includes/post/pharmacist_post_reservation_actions.php. -->
        <form method="POST">
            <!-- Reservation id is filled in by openRejectModal(). -->
            <input type="hidden" name="reservation_id" id="rejectResId" value="">
            <input type="hidden" name="action_type" value="REJECTED">
            <div class="modal-description" style="margin-bottom: 18px;">
                Please select a reason for rejecting this request.
            </div>

            <div class="modal-input-group">
                <label for="reject_reason">Reason</label>
                <select name="reject_reason" id="reject_reason" class="modal-input" required>
                    <option value="" disabled selected>Select a reason</option>
                    <option value="PRESCRIPTION_NOT_VALID">Prescription not valid or unclear</option>
                    <option value="PRESCRIPTION_REQUIRED">Correct prescription required</option>
                    <option value="DRUG_INTERACTION_DETECTED">Drug interaction detected</option>
                    <option value="OUT_OF_STOCK">Medicine out of stock</option>
                    <option value="DUPLICATE_RESERVATION">Duplicate request</option>
                    <option value="OTHER">Others</option>
                </select>
            </div>

            <!-- Note box is shown and required only for 'Others' (syncRejectReason()). -->
            <div class="modal-input-group" id="rejectOtherBox" style="display:none;">
                <label for="rejectNote">Other reason</label>
                <textarea name="pharmacist_note" id="rejectNote" class="modal-input" rows="3" placeholder="Write the rejection reason..."></textarea>
            </div>

            <div style="margin-top: 26px; display: flex; justify-content: center;">
                <button type="button" class="btn-modal-cancel" onclick="closeRejectModal()">Cancel</button>
                <button type="submit" class="btn-modal-action" style="background:#ef4444;">Confirm Rejection</button>
            </div>
        </form>
    </div>
</div>
