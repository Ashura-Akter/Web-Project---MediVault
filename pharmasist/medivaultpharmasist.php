<?php
/**
 * MediVault - Pharmacist Dashboard  (ENTRY POINT)
 *
 * The old single 2200-line file was split into small files. This file only defines the module root and
 * loads the parts in the correct order. Keep the name medivaultpharmasist.php: the login page, the top bar
 * and all redirects still point to it.
 *
 * FILE MAP
 *   BACKEND (PHP + SQL)  includes/
 *     pharmacist_auth_profile.php          session, access control, pharmacist + pharmacy profile, auto OCR worker
 *     pharmacist_helpers.php               helper functions + $res_tab_filters (tab WHERE fragments)
 *     pharmacist_ajax_counts.php           JSON endpoint  ?counts=1   (live counters)
 *     pharmacist_ajax_suggest.php          JSON endpoint  ?suggest=1  (live search)
 *     pharmacist_post_router.php           all POST forms, calls the 4 handlers in includes/post/:
 *       post/pharmacist_post_notifications.php          bell: mark as read
 *       post/pharmacist_post_add_stock.php              add a stock batch
 *       post/pharmacist_post_reservation_actions.php    approve / reject / accept reservation
 *       post/pharmacist_post_confirm_cancellation.php   cancel reservation with reason
 *     pharmacist_page_state.php            active tab, search, popup flags
 *     pharmacist_dashboard_stats.php       KPI numbers
 *     pharmacist_notification_data.php     notification bell data
 *     pharmacist_medicine_query.php        medicine list query (Dashboard tab)
 *
 *   FRONTEND (HTML)  views/
 *     layout/   pharmacist_head, pharmacist_sidebar, pharmacist_main_content, pharmacist_footer
 *     modals/   add_stock, confirmations, cancel_reservation, reject_request, rx_viewer
 *     partials/ pharmacist_search_bar, pharmacist_kpi_cards
 *     tabs/     pharmacist_tab_router + one file per tab (dashboard, in_stock, low_stock, out_of_stock,
 *               expiring_soon, pending, approved, rejected, reservations, reservation_history, order_history)
 *
 *   FRONTEND (CSS / JS)  assets/
 *     css/ pharmacist_dashboard.css, pharmacist_rx_viewer.css
 *     js/  pharmacist_dashboard_core, pharmacist_deadline_picker, pharmacist_live_search,
 *          pharmacist_live_counts, pharmacist_rx_viewer
 *
 * EXPECTED SIBLINGS (unchanged): ../database/queries.php, ../common/topbar.php, ../common/common.css,
 *                                ../login/login.php, auto_ocr_worker.php, prescription_view.php
 */

// Base folder of this module. Every include uses it, and every included file refuses to run without it.
if (!defined('PHARMACIST_ROOT')) {
    define('PHARMACIST_ROOT', __DIR__);
}

// =============================== BACKEND (runs before any HTML is sent) ===============================
// 1. Session, login / approval check, pharmacist + pharmacy profile.
require_once PHARMACIST_ROOT . '/includes/pharmacist_auth_profile.php';
// 2. Helper functions and the tab filters.
require_once PHARMACIST_ROOT . '/includes/pharmacist_helpers.php';
// 3. AJAX endpoints (they print JSON and stop).
require_once PHARMACIST_ROOT . '/includes/pharmacist_ajax_counts.php';
require_once PHARMACIST_ROOT . '/includes/pharmacist_ajax_suggest.php';
// 4. Form submissions (POST). Each handler redirects and exits when it has done its work.
require_once PHARMACIST_ROOT . '/includes/pharmacist_post_router.php';
// 5. Data for the page: active tab, KPI numbers, notification bell, medicine list.
require_once PHARMACIST_ROOT . '/includes/pharmacist_page_state.php';
require_once PHARMACIST_ROOT . '/includes/pharmacist_dashboard_stats.php';
require_once PHARMACIST_ROOT . '/includes/pharmacist_notification_data.php';
require_once PHARMACIST_ROOT . '/includes/pharmacist_medicine_query.php';

// =============================== FRONTEND (HTML output) ===============================
// <html>, <head> and <body>
require_once PHARMACIST_ROOT . '/views/layout/pharmacist_head.php';
// Modals (hidden until opened)
require_once PHARMACIST_ROOT . '/views/modals/pharmacist_modal_add_stock.php';
require_once PHARMACIST_ROOT . '/views/modals/pharmacist_modal_confirmations.php';
require_once PHARMACIST_ROOT . '/views/modals/pharmacist_modal_cancel_reservation.php';
require_once PHARMACIST_ROOT . '/views/modals/pharmacist_modal_reject_request.php';
// Shared top bar (logo, notification bell, profile menu) from the common module.
require_once PHARMACIST_ROOT . '/../common/topbar.php';
// Sidebar, then the main content (search, KPI cards, active tab).
require_once PHARMACIST_ROOT . '/views/layout/pharmacist_sidebar.php';
require_once PHARMACIST_ROOT . '/views/layout/pharmacist_main_content.php';
// Viewer modal, JS config, scripts and the closing </body></html>.
require_once PHARMACIST_ROOT . '/views/layout/pharmacist_footer.php';
