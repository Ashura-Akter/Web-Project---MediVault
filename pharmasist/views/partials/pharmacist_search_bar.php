<?php
/**
 * MediVault - Pharmacist module
 * File    : views/partials/pharmacist_search_bar.php
 * Title   : Search bar with live suggestions
 *
 * FRONTEND (HTML)
 * GET form that searches the current tab. Suggestions come from includes/pharmacist_ajax_suggest.php.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');
?>
    <!-- Fixed top search area: searches the current tab and shows live suggestions. -->
    <!-- GET form: submitting reloads the current tab with ?search=... -->
    <form method="GET" class="search-container" id="searchForm" autocomplete="off">
        <!-- Keeps the current tab when searching. -->
        <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
        <button type="submit" class="search-icon" aria-label="Search"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><line x1="20.5" y1="20.5" x2="16" y2="16"></line></svg></button>
        <!-- Search input. The placeholder changes with the tab. Live suggestions: assets/js/pharmacist_live_search.js. -->
        <input type="text" name="search" id="liveSearch" class="search-bar" placeholder="<?= $tab === 'all' ? 'search by name, symptoms or category' : (isset($res_tab_filters[$tab]) ? 'Search by name or ID' : 'Search by name') ?>" value="<?= htmlspecialchars($search) ?>" autocomplete="off">
        <!-- Suggestion dropdown (filled by JS from the ?suggest=1 endpoint). -->
        <div class="suggest-box" id="suggestBox" hidden></div>
    </form>
