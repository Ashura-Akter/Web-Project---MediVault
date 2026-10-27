<?php

session_start();

require_once __DIR__ . '/../database/queries.php';
require_once __DIR__ . '/../database/bootstrap_admin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$role = strtoupper(trim($_POST['role'] ?? ''));

$_SESSION['login_email'] = $email;
$_SESSION['login_role'] = $role;

if ($email === '' || $password === '' || $role === '') {
    $_SESSION['login_error'] =
        'Please enter email, password and select user type.';

    header('Location: login.php');
    exit;
}

$allowedRoles = [
    'PATIENT',
    'PHARMACIST',
    'ADMIN'
];

// Bootstrap the requested default admin account on a fresh/legacy database.
if ($role === 'ADMIN') {
    ensure_default_admin($conn);
}

if (!in_array($role, $allowedRoles, true)) {
    $_SESSION['login_error'] =
        'Invalid user type selected.';

    header('Location: login.php');
    exit;
}

$user = get_user_by_email($conn, $email);

if (!$user) {
    $_SESSION['login_error'] =
        'Incorrect email.';

    header('Location: login.php');
    exit;
}

if (!(bool)$user['is_active']) {
    $_SESSION['login_error'] =
        'This user account is inactive.';

    header('Location: login.php');
    exit;
}

if (strtoupper($user['role']) !== $role) {
    $_SESSION['login_error'] =
        'Incorrect user type selected for this email.';

    header('Location: login.php');
    exit;
}

$passwordIsValid = password_verify(
    $password,
    $user['password_hash']
);

/*
 * Existing supplied seed database contains placeholder password strings.
 * This fallback does not change the database.
 */
if (
    !$passwordIsValid &&
    hash_equals((string)$user['password_hash'], $password)
) {
    $passwordIsValid = true;
}

if (!$passwordIsValid) {
    $_SESSION['login_error'] =
        'Incorrect password.';

    header('Location: login.php');
    exit;
}

if ($user['role'] === 'PHARMACIST') {

    $stmt = $conn->prepare(
        "SELECT approval_status
         FROM pharmacist_profiles
         WHERE user_id = ?
         LIMIT 1"
    );

    $stmt->bind_param(
        'i',
        $user['user_id']
    );

    $stmt->execute();

    $approvalData =
        $stmt->get_result()->fetch_assoc();

    if (
        !$approvalData ||
        $approvalData['approval_status'] !== 'APPROVED'
    ) {
        $_SESSION['login_error'] =
            'Pharmacist account is not approved by admin yet.';

        header('Location: login.php');
        exit;
    }
}

session_regenerate_id(true);

$_SESSION['user_id'] =
    (int)$user['user_id'];

$_SESSION['full_name'] =
    $user['full_name'];

$_SESSION['email'] =
    $user['email'];

$_SESSION['role'] =
    $user['role'];

unset(
    $_SESSION['login_error'],
    $_SESSION['login_email'],
    $_SESSION['login_role']
);

/*
 * Patient login starts the patient flow immediately.
 */
if ($user['role'] === 'PATIENT') {
    header('Location: ../patient/browse.php');
    exit;
}

/*
 * Pharmacist login opens the integrated pharmacist dashboard.
 * The dashboard then loads the pharmacist/profile/pharmacy details from MySQL.
 */
if ($user['role'] === 'PHARMACIST') {
    header('Location: ../pharmasist/medivaultpharmasist.php');
    exit;
}

/* Admin login opens the integrated admin dashboard. */
if ($user['role'] === 'ADMIN') {
    header('Location: ../admin/index.php');
    exit;
}

header('Location: ../common/home.php');
exit;

?>