<?php
/**
 * MediVault - Pharmacist module
 * File    : includes/pharmacist_post_router.php
 * Title   : POST request router
 *
 * BACKEND (PHP)
 * Every form of the dashboard is submitted with POST to medivaultpharmasist.php.
 * Each handler below checks for its own form field, does its database work, redirects and exits.
 * The ORDER of the handlers is the same as in the original single file.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 0. Notification bell: mark one / all notifications as read.
    require_once PHARMACIST_ROOT . '/includes/post/pharmacist_post_notifications.php';

    // 1. Add stock: insert a new inventory batch.
    require_once PHARMACIST_ROOT . '/includes/post/pharmacist_post_add_stock.php';

    // 2. Reservation / prescription actions: APPROVED, REJECTED, RESERVED, CANCELLED (opens the cancel modal).
    require_once PHARMACIST_ROOT . '/includes/post/pharmacist_post_reservation_actions.php';

    // 3. Final cancellation: save the reason chosen in the cancel modal.
    require_once PHARMACIST_ROOT . '/includes/post/pharmacist_post_confirm_cancellation.php';
}
