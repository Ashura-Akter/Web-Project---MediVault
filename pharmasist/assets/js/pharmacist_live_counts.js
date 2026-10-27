/**
 * MediVault - Pharmacist module
 * File    : assets/js/pharmacist_live_counts.js
 * Title   : Live KPI and bell counters
 *
 * FRONTEND (JavaScript)
 * Polls ?counts=1 every 20 seconds. Server side: includes/pharmacist_ajax_counts.php.
 * Needs window.MV_PHARMACIST (printed by views/layout/pharmacist_footer.php).
 */

// Keeps the Reserved Request card and the bell badge up to date without reloading the page
(function () {
    // DOM targets: KPI cards, bell button and the 'fresh' hint.
    var card = document.getElementById('kpiReserved'), newOrderCard = document.getElementById('kpiNewOrders'), bell = document.getElementById('nbBtn'), fresh = document.getElementById('nbFresh');
    // Starting values come from window.MV_PHARMACIST (printed by views/layout/pharmacist_footer.php).
    var cfg = window.MV_PHARMACIST || {}, lastBadge = Number(cfg.badge) || 0, lastReserved = Number(cfg.reserved) || 0, lastNewOrders = Number(cfg.newOrders) || 0;
    // setBadge(): show / update / remove the red number on the bell icon.
    function setBadge(n) {
        if (!bell) return;
        var b = bell.querySelector('.nb-badge');
        if (n > 0) {
            if (!b) { b = document.createElement('span'); b.className = 'nb-badge'; bell.appendChild(b); }
            b.textContent = n > 99 ? '99+' : n;
        } else if (b) {
            b.remove();
        }
    }
    // poll(): fetch ?counts=1 (includes/pharmacist_ajax_counts.php), update the cards and the badge,
    // and show the 'fresh' hint when a number went up.
    function poll() {
        if (document.hidden) return;
        fetch(window.location.pathname + '?counts=1', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (card) card.textContent = d.reserved;
                if (newOrderCard) newOrderCard.textContent = d.new_orders || 0;
                setBadge(d.badge);
                if (fresh && (d.reserved > lastReserved || (d.new_orders || 0) > lastNewOrders || d.badge > lastBadge)) fresh.hidden = false;
                lastReserved = d.reserved; lastNewOrders = d.new_orders || 0; lastBadge = d.badge;
            })
            .catch(function () {});
    }
    // Poll every 20 seconds (skipped while the browser tab is hidden).
    setInterval(poll, 20000);
})();
