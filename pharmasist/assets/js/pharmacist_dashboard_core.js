/**
 * MediVault - Pharmacist module
 * File    : assets/js/pharmacist_dashboard_core.js
 * Title   : Core UI scripts: profile menu and modals
 *
 * FRONTEND (JavaScript)
 * Profile dropdown, Add Stock modal, Reject modal and the 'Other reason' text boxes.
 */

// Profile Dropdown Toggle Script
const profileBtn = document.getElementById('profileDropdownBtn');
const profileMenu = document.getElementById('profileMenu');

// Open / close the profile menu. A click anywhere else closes it.
if (profileBtn) {
    profileBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        profileMenu.classList.toggle('show');
    });

    document.addEventListener('click', function(e) {
        if (!profileMenu.contains(e.target) && !profileBtn.contains(e.target)) {
            profileMenu.classList.remove('show');
        }
    });
}

// Modal Control Functions for Add Quantity Overlay
// Fill the hidden medicine_id field and the title, then show the Add Stock modal.
function openStockModal(medId, medName) {
    document.getElementById('modalMedicineId').value = medId;
    document.getElementById('stockModalMedName').innerText = 'Add Stock for ' + medName;
    document.getElementById('addStockModal').style.display = 'flex';
}

// Hide the Add Stock modal.
function closeStockModal() {
    document.getElementById('addStockModal').style.display = 'none';
}

// Reject popup: the reason is chosen here before the request is rejected.
// Open the reject modal: store the reservation id and copy any note typed on the card.
function openRejectModal(resId, cardForm) {
    document.getElementById('rejectResId').value = resId;
    var noteField = cardForm ? cardForm.elements['pharmacist_note'] : null;
    document.getElementById('rejectNote').value = noteField ? noteField.value : '';
    document.getElementById('reject_reason').selectedIndex = 0;
    syncRejectReason();
    document.getElementById('rejectModal').style.display = 'flex';
}

// Show the 'Other reason' textarea (and make it required) only when 'Others' is selected.
function syncRejectReason() {
    var select = document.getElementById('reject_reason');
    var box = document.getElementById('rejectOtherBox');
    var note = document.getElementById('rejectNote');
    var isOther = select && select.value === 'OTHER';
    if (box) box.style.display = isOther ? 'block' : 'none';
    if (note) note.required = isOther;
    if (!isOther && note) note.value = '';
}

// Same behaviour for the cancel-reservation modal.
function syncCancelReason() {
    var select = document.getElementById('cancel_reason');
    var box = document.getElementById('cancelOtherBox');
    var note = document.getElementById('cancelOtherNote');
    var isOther = select && select.value === 'OTHER';
    if (box) box.style.display = isOther ? 'block' : 'none';
    if (note) note.required = isOther;
    if (!isOther && note) note.value = '';
}

// Close the reject modal and reset its fields.
function closeRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
    syncRejectReason();
}

// Connect the selects to the sync functions and run them once for the initial state.
document.getElementById('reject_reason')?.addEventListener('change', syncRejectReason);
document.getElementById('cancel_reason')?.addEventListener('change', syncCancelReason);
syncRejectReason();
syncCancelReason();
