<?php
/**
 * MediVault - Pharmacist module
 * File    : views/modals/pharmacist_modal_cancel_reservation.php
 * Title   : Modal: cancel reservation (reason)
 *
 * FRONTEND (HTML + PHP condition)
 * Shown when ?show_cancel_modal=1. Posts the reason to pharmacist_post_confirm_cancellation.php.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');
?>
<!-- CANCEL RESERVATION MODAL WITH REASONS -->
<!-- Rendered only when ?show_cancel_modal=1 is in the URL (set by the Reject button in the Reservations tab). -->
<?php if ($show_cancelled_popup): ?>
<div class="modal-overlay" id="cancelledModal">
    <div class="modal-card">
        <div class="modal-title" style="color:#000;">
            <span style="color: #ef4444;">❌</span> Cancel Reservation
        </div>
        
        <!-- Posts confirm_cancellation to includes/post/pharmacist_post_confirm_cancellation.php. -->
        <form method="POST">
            <input type="hidden" name="reservation_id" value="<?= $cancelled_res_id ?>">
            <div class="modal-description" style="margin-bottom: 12px;">
                Please select a reason for cancelling this reservation.
            </div>
            
            <div class="reason-row">
                <span>Please Select Reason:</span>
                <select name="cancel_reason" id="cancel_reason" class="reason-select" required>
                    <option value="OUT_OF_STOCK" selected>Out of Stock</option>
                    <option value="DRUG_INTERACTION_DETECTED">Drug Interaction Detected</option>
                    <option value="PRESCRIPTION_REQUIRED">Prescription Required</option>
                    <option value="PRESCRIPTION_NOT_VALID">Prescription Not Valid</option>
                    <option value="DUPLICATE_RESERVATION">Duplicate Reservation</option>
                    <option value="OTHER">Others</option>
                </select>
            </div>
            <!-- Textarea is shown by JS (syncCancelReason) only when the reason is 'Others'. -->
            <div class="modal-input-group" id="cancelOtherBox" style="display:none;margin-top:14px;">
                <label for="cancelOtherNote">Other reason</label>
                <textarea name="cancellation_note" id="cancelOtherNote" class="modal-input" rows="3" placeholder="Write the cancellation reason..."></textarea>
            </div>

            <button type="submit" name="confirm_cancellation" class="btn-modal-action">Confirm Cancellation</button>
        </form>
    </div>
</div>
<?php endif; ?>
