<?php
/**
 * MediVault - Pharmacist module
 * File    : views/modals/pharmacist_modal_rx_viewer.php
 * Title   : Modal: prescription viewer
 *
 * FRONTEND (HTML)
 * Full-screen overlay with an iframe that shows the uploaded prescription (assets/js/pharmacist_rx_viewer.js).
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');
?>
<!-- PRESCRIPTION VIEWER -->
<div class="rx-viewer-overlay" id="rxViewerOverlay" aria-hidden="true">
    <div class="rx-viewer-card" role="dialog" aria-modal="true" aria-label="Prescription viewer">
        <button type="button" class="rx-viewer-close" onclick="closePrescriptionViewer()" aria-label="Close prescription">&times;</button>
        <!-- The iframe loads prescription_view.php?reservation_id=... (src is set by openPrescriptionViewer()). -->
        <iframe id="rxViewerFrame" title="Uploaded prescription" src="about:blank"></iframe>
    </div>
</div>
