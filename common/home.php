<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login/login.php');
    exit;
}

$role = strtoupper($_SESSION['role'] ?? '');

if ($role === 'PATIENT') {
    header('Location: ../patient/browse.php');
    exit;
}
if ($role === 'ADMIN') {
    header('Location: ../admin/index.php?tab=all-medicine');
    exit;
}
if ($role === 'PHARMACIST') {
    header('Location: ../pharmasist/medivaultpharmasist.php?tab=all');
    exit;
}

/*
 * Patient is linked now.
 * Existing Pharmacist/Admin pages can remain connected here later.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MediVault</title>
</head>
<body>
<h3>MediVault</h3>
<p>Logged in as <?= htmlspecialchars($role) ?>.</p>
</body>
</html>
