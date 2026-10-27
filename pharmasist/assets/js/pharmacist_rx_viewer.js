/**
 * MediVault - Pharmacist module
 * File    : assets/js/pharmacist_rx_viewer.js
 * Title   : Prescription viewer overlay
 *
 * FRONTEND (JavaScript)
 * openPrescriptionViewer(id) / closePrescriptionViewer(). The iframe loads prescription_view.php.
 */

// Open the viewer: load prescription_view.php for this reservation into the iframe and show the overlay.
function openPrescriptionViewer(reservationId){
    var overlay=document.getElementById('rxViewerOverlay');
    var frame=document.getElementById('rxViewerFrame');
    if(!overlay||!frame)return;
    frame.src='prescription_view.php?reservation_id='+encodeURIComponent(reservationId);
    overlay.classList.add('is-open');
    overlay.setAttribute('aria-hidden','false');
    document.body.style.overflow='hidden';
}
// Close the viewer and unload the iframe.
function closePrescriptionViewer(){
    var overlay=document.getElementById('rxViewerOverlay');
    var frame=document.getElementById('rxViewerFrame');
    if(!overlay)return;
    overlay.classList.remove('is-open');
    overlay.setAttribute('aria-hidden','true');
    if(frame)frame.src='about:blank';
    document.body.style.overflow='';
}
// Click on the dark background or press Escape to close.
(function(){
    var overlay=document.getElementById('rxViewerOverlay');
    if(overlay)overlay.addEventListener('click',function(e){if(e.target===overlay)closePrescriptionViewer();});
    document.addEventListener('keydown',function(e){if(e.key==='Escape')closePrescriptionViewer();});
})();
