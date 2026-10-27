<?php
/**
 * MediVault - Pharmacist module
 * File    : views/modals/pharmacist_modal_confirmations.php
 * Title   : Modals: approval and new-order confirmation
 *
 * FRONTEND (HTML + PHP conditions)
 * Popups shown after a redirect: restricted medicine approved, reservation accepted, online order received.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');
?>
<!-- APPROVAL / ONLINE ORDER CONFIRMATION MODALS -->
<!-- Shown after a restricted-medicine request was approved (?reservation_accepted=1&approval_type=restricted). -->
<?php if ($show_accepted_popup && $approval_type === 'restricted'): ?>
<div class="modal-overlay" id="restrictedApprovedModal">
    <div class="modal-card">
        <div class="modal-title" style="color:#000;">
            <span style="color:#22c55e;">✅</span> Restricted Medicine Approved
        </div>
        <div class="modal-description">
            The restricted-medicine prescription has been approved successfully.<br>
            The patient can now buy the restricted medicine.
        </div>
        <a href="medivaultpharmasist.php?tab=approved" class="btn-modal-action">View Approved History</a>
    </div>
</div>
<!-- Shown after a normal reservation was accepted (?reservation_accepted=1). -->
<?php elseif ($show_accepted_popup): ?>
<div class="modal-overlay" id="reservationAcceptedModal">
    <div class="modal-card">
        <div class="modal-title" style="color:#000;">
            <span style="color:#22c55e;">✅</span> Reservation Accepted
        </div>
        <div class="modal-description">
            The reservation has been accepted successfully.<br>
            The medicines are now reserved for the patient.
        </div>
        <a href="medivaultpharmasist.php?tab=approved" class="btn-modal-action">View Approved History</a>
    </div>
</div>
<?php endif; ?>

<!-- Shown when a new online order arrived (?order_received=1). -->
<?php if ($show_order_received_popup): ?>
<div class="modal-overlay" id="onlineOrderReceivedModal">
    <div class="modal-card">
        <div class="modal-title" style="color:#000;">
            <span style="color:#078f86;">🛒</span> New Online Order Received
        </div>
        <div class="modal-description">
            A new online order has been received successfully.<br>
            Please review the order and process it from Order History.
        </div>
        <a href="medivaultpharmasist.php?tab=order_history" class="btn-modal-action">View Order History</a>
    </div>
</div>
<?php endif; ?>
