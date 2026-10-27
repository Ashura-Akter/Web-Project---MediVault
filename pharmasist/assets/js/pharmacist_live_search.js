/**
 * MediVault - Pharmacist module
 * File    : assets/js/pharmacist_live_search.js
 * Title   : Live search suggestions
 *
 * FRONTEND (JavaScript)
 * Shows suggestions while typing. Server side: includes/pharmacist_ajax_suggest.php.
 * Needs window.MV_PHARMACIST.tab (printed by views/layout/pharmacist_footer.php).
 */

// Live search: shows matching suggestions while typing
(function () {
    // DOM: the search input and the suggestion box (rendered by views/partials/pharmacist_search_bar.php).
    var input = document.getElementById('liveSearch'), box = document.getElementById('suggestBox');
    if (!input || !box) return;
    // tab comes from window.MV_PHARMACIST (printed by views/layout/pharmacist_footer.php).
    var form = input.form, tab = (window.MV_PHARMACIST && window.MV_PHARMACIST.tab) || 'all', timer = null, items = [], active = -1, reqId = 0;

    // hide(): close the suggestion box and reset the state.
    function hide() { box.hidden = true; box.innerHTML = ''; items = []; active = -1; }
    // pick(): put the chosen suggestion into the input and submit the search form.
    function pick(label) { input.value = label; hide(); form.submit(); }
    function setActive(i) {
        for (var k = 0; k < box.children.length; k++) box.children[k].classList.toggle('active', k === i);
        active = i;
    }
    // render(): draw the suggestion rows and highlight the matching part with <mark>.
    function render(list, q) {
        box.innerHTML = '';
        items = list;
        active = -1;
        if (!list.length) { box.hidden = true; return; }
        list.forEach(function (label) {
            var d = document.createElement('div');
            d.className = 'suggest-item';
            var at = label.toLowerCase().indexOf(q.toLowerCase());
            if (at >= 0) {
                d.appendChild(document.createTextNode(label.slice(0, at)));
                var m = document.createElement('mark');
                m.textContent = label.slice(at, at + q.length);
                d.appendChild(m);
                d.appendChild(document.createTextNode(label.slice(at + q.length)));
            } else {
                d.textContent = label;
            }
            d.addEventListener('mousedown', function (ev) { ev.preventDefault(); pick(label); });
            box.appendChild(d);
        });
        box.hidden = false;
    }
    // Ask the server (includes/pharmacist_ajax_suggest.php). reqId makes sure old answers cannot overwrite newer ones.
    function fetchSuggestions() {
        var q = input.value.trim();
        if (q === '') { hide(); return; }
        var id = ++reqId;
        fetch(window.location.pathname + '?suggest=1&tab=' + encodeURIComponent(tab) + '&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (list) { if (id === reqId && document.activeElement === input) render(list, q); })
            .catch(function () { hide(); });
    }
    // Debounce: wait 180 ms after the last key press before asking the server.
    input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(fetchSuggestions, 180); });
    input.addEventListener('focus', function () { if (input.value.trim() !== '') fetchSuggestions(); });
    // Keyboard: arrow up / down move, Enter selects, Escape closes.
    input.addEventListener('keydown', function (ev) {
        if (box.hidden) return;
        if (ev.key === 'ArrowDown') { ev.preventDefault(); setActive((active + 1) % items.length); }
        else if (ev.key === 'ArrowUp') { ev.preventDefault(); setActive((active - 1 + items.length) % items.length); }
        else if (ev.key === 'Enter' && active >= 0) { ev.preventDefault(); pick(items[active]); }
        else if (ev.key === 'Escape') { hide(); }
    });
    // A click outside the form closes the suggestion box.
    document.addEventListener('click', function (ev) { if (!form.contains(ev.target)) hide(); });
})();
