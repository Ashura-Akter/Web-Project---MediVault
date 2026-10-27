<?php
/**
 * MediVault - Pharmacist module
 * File    : includes/pharmacist_auth_profile.php
 * Title   : Session, access control and pharmacist / pharmacy profile
 *
 * BACKEND (PHP + SQL)
 * Runs first. Starts the session, allows only a logged-in and admin-approved PHARMACIST,
 * loads the pharmacist profile + pharmacy from the database and runs the auto-OCR worker.
 * Provides: $pharmacistId, $pharmacist, $pharmacyId, $pharmacyName, $pharmacyCampus, $initial, $conn.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');

// Start (or resume) the PHP session. The login page stored user_id and role in it.
session_start();

// Load the DB connection ($conn = mysqli) and the shared helper functions
// (add_notification, mark_notifications_read, ensure_pharmacy_for_profile, medicine_image_html ...).
require_once PHARMACIST_ROOT . '/../database/queries.php';

// Pharmacist pages are available only to a logged-in PHARMACIST.
if (!isset($_SESSION['user_id']) || strtoupper($_SESSION['role'] ?? '') !== 'PHARMACIST') {
    header('Location: ../login/login.php');
    exit;
}

// Id of the logged-in pharmacist (cast to int so it is always safe to use in queries).
$pharmacistId = (int)$_SESSION['user_id'];

/* Run the auto-OCR worker at most once every 4 minutes (fire and forget). */
require_once PHARMACIST_ROOT . '/auto_ocr_worker.php';
run_auto_ocr_worker($conn);

// Load the logged-in pharmacist and their pharmacy from the database.
// SQL: load the pharmacist account, profile and pharmacy in ONE query.
//   users u             -> account data (name, email, phone, role, is_active)
//   pharmacist_profiles -> INNER JOIN: every pharmacist must have a profile (license_no, approval_status)
//   pharmacies p        -> LEFT JOIN: the pharmacy may not exist yet (pharmacist registered as 'Others')
//   Filter: only ACTIVE users with role PHARMACIST. LIMIT 1 because user_id is unique.
// The '?' placeholder is bound below, so the query is safe from SQL injection.
$profileStmt = $conn->prepare(
    "SELECT
        u.user_id,
        u.full_name,
        u.email,
        u.phone,
        u.date_of_birth,
        u.address AS user_address,
        u.role,
        pp.pharmacist_profile_id,
        pp.pharmacy_id,
        pp.pharmacy_or_company_name,
        pp.license_no,
        pp.approval_status,
        p.pharmacy_name,
        p.campus_name,
        p.address AS pharmacy_address,
        p.phone AS pharmacy_phone,
        p.email AS pharmacy_email,
        p.opening_time,
        p.closing_time
     FROM users u
     JOIN pharmacist_profiles pp ON pp.user_id = u.user_id
     LEFT JOIN pharmacies p ON p.pharmacy_id = pp.pharmacy_id
     WHERE u.user_id = ? AND u.role = 'PHARMACIST' AND u.is_active = TRUE
     LIMIT 1"
);
// Bind the session user id to the '?' placeholder ('i' = integer) and run the query.
$profileStmt->bind_param('i', $pharmacistId);
$profileStmt->execute();
// $pharmacist = one associative row, or null when the account is missing / inactive / not a pharmacist.
$pharmacist = $profileStmt->get_result()->fetch_assoc();

// No valid pharmacist found: destroy the session and send the person back to the login page.
if (!$pharmacist) {
    session_destroy();
    header('Location: ../login/login.php');
    exit;
}

// Business rule: the dashboard is usable only after an admin has APPROVED the pharmacist account.
if ($pharmacist['approval_status'] !== 'APPROVED') {
    $_SESSION['login_error'] = 'Pharmacist account is not approved by admin yet.';
    header('Location: ../login/login.php');
    exit;
}

// $pharmacyId = the pharmacy this pharmacist works for. EVERY query in this module is scoped to it.
$pharmacyId = (int)($pharmacist['pharmacy_id'] ?? 0);
if ($pharmacyId <= 0) {
    // Approved pharmacist who registered with a new ("Others") pharmacy: create and link that pharmacy now.
    $pharmacyId = ensure_pharmacy_for_profile($conn, (int)$pharmacist['pharmacist_profile_id']);
    if ($pharmacyId <= 0) {
        die('No pharmacy is assigned to this pharmacist account.');
    }
    // SQL: re-read name and campus of the pharmacy that was just created and linked.
    $reload = $conn->prepare("SELECT pharmacy_name, campus_name FROM pharmacies WHERE pharmacy_id = ? LIMIT 1");
    $reload->bind_param('i', $pharmacyId);
    $reload->execute();
    $ph = $reload->get_result()->fetch_assoc() ?: [];
    $pharmacist['pharmacy_id'] = $pharmacyId;
    $pharmacist['pharmacy_name'] = $ph['pharmacy_name'] ?? $pharmacist['pharmacy_or_company_name'];
    $pharmacist['campus_name'] = $ph['campus_name'] ?? null;
}

// Display values used by the top bar: pharmacy name, campus label and avatar initial.
$pharmacyName = $pharmacist['pharmacy_name'] ?: $pharmacist['pharmacy_or_company_name'];
$pharmacyCampus = $pharmacist['campus_name'] ?: 'Campus Health Centre';
$initial = strtoupper(substr(trim($pharmacist['full_name']), 0, 1));
