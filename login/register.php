<?php
session_start();
require_once __DIR__ . '/../database/connection.php';

$error = '';
$success = false;
$registeredName = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = strtoupper(trim($_POST['role'] ?? ''));

    $allowedRoles = ['PATIENT', 'PHARMACIST'];

    if ($name === '' || $email === '' || $phone === '' || $password === '' || $role === '') {
        $error = 'Please complete all fields.';
    } elseif (!in_array($role, $allowedRoles, true)) {
        $error = 'Please select a valid user type.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {

        $check = $conn->prepare(
            "SELECT user_id FROM users WHERE email = ? OR phone = ? LIMIT 1"
        );
        $check->bind_param('ss', $email, $phone);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();

        if ($existing) {
            $error = 'Email or phone number already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            // A pharmacist account needs a matching pharmacist_profiles row
            // because that table stores pharmacy, license and approval data.
            if ($role === 'PHARMACIST') {
                $pharmacyChoice = trim((string)($_POST['pharmacy_id'] ?? ''));
                $otherPharmacyName = trim((string)($_POST['other_pharmacy_name'] ?? ''));
                $selectedPharmacyId = ctype_digit($pharmacyChoice) ? (int)$pharmacyChoice : 0;
                $pharmacy = null;

                if ($pharmacyChoice === 'OTHER') {
                    if ($otherPharmacyName === '') {
                        $error = 'Please enter your pharmacy or company name.';
                    } elseif (mb_strlen($otherPharmacyName) > 150) {
                        $error = 'Pharmacy or company name must be 150 characters or less.';
                    }
                } elseif ($selectedPharmacyId > 0) {
                    $pharmacyStmt = $conn->prepare(
                        "SELECT pharmacy_id, pharmacy_name
                         FROM pharmacies
                         WHERE pharmacy_id = ? AND is_active = TRUE
                         LIMIT 1"
                    );
                    $pharmacyStmt->bind_param('i', $selectedPharmacyId);
                    $pharmacyStmt->execute();
                    $pharmacy = $pharmacyStmt->get_result()->fetch_assoc();
                }

                $adminResult = $conn->query(
                    "SELECT user_id
                     FROM users
                     WHERE role = 'ADMIN' AND is_active = TRUE
                     ORDER BY user_id
                     LIMIT 1"
                );
                $admin = $adminResult ? $adminResult->fetch_assoc() : null;

                if ((!$pharmacy && $pharmacyChoice !== 'OTHER') || !$admin) {
                    $error = 'Pharmacist registration cannot be completed because no active pharmacy or admin account is available.';
                } elseif ($error === '') {
                    $conn->begin_transaction();

                    try {
                        $stmt = $conn->prepare(
                            "INSERT INTO users
                            (full_name, email, phone, password_hash, role)
                            VALUES (?, ?, ?, ?, ?)"
                        );
                        $stmt->bind_param('sssss', $name, $email, $phone, $hash, $role);
                        $stmt->execute();
                        $newUserId = (int)$conn->insert_id;

                        // Pharmacists must be approved by an administrator before login.
                        $licenseNo = 'PH-' . date('Y') . '-' . str_pad((string)$newUserId, 4, '0', STR_PAD_LEFT);
                        if ($pharmacyChoice === 'OTHER') {
                            $profileStmt = $conn->prepare(
                                "INSERT INTO pharmacist_profiles
                                 (user_id, pharmacy_id, pharmacy_or_company_name, license_no,
                                  approval_status, approved_by_admin_id, admin_note, registered_at, reviewed_at)
                                 VALUES (?, NULL, ?, ?, 'PENDING', NULL, 'Waiting for admin approval.', NOW(), NULL)"
                            );
                            $profileStmt->bind_param('iss', $newUserId, $otherPharmacyName, $licenseNo);
                        } else {
                            $profileStmt = $conn->prepare(
                                "INSERT INTO pharmacist_profiles
                                 (user_id, pharmacy_id, pharmacy_or_company_name, license_no,
                                  approval_status, approved_by_admin_id, admin_note, registered_at, reviewed_at)
                                 VALUES (?, ?, ?, ?, 'PENDING', NULL, 'Waiting for admin approval.', NOW(), NULL)"
                            );
                            $profileStmt->bind_param(
                                'iiss',
                                $newUserId,
                                $pharmacy['pharmacy_id'],
                                $pharmacy['pharmacy_name'],
                                $licenseNo
                            );
                        }
                        $profileStmt->execute();

                        // Notify the active admin so the request appears in Pharmacist Approvals.
                        $notificationTitle = 'New Pharmacist Registration';
                        $notificationMessage = $name . ' has registered as a pharmacist and is waiting for approval.';
                        $notificationStmt = $conn->prepare(
                            "INSERT INTO notifications
                             (user_id, notification_type, title, message, is_read, created_at)
                             SELECT user_id, 'GENERAL', ?, ?, FALSE, NOW()
                             FROM users WHERE role = 'ADMIN' AND is_active = TRUE"
                        );
                        $notificationStmt->bind_param('ss', $notificationTitle, $notificationMessage);
                        $notificationStmt->execute();

                        $conn->commit();
                        $success = true;
                        $registeredName = $name;
                    } catch (Throwable $e) {
                        $conn->rollback();
                        $error = 'Registration failed. Please try again.';
                    }
                }
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO users
                    (full_name, email, phone, password_hash, role)
                    VALUES (?, ?, ?, ?, ?)"
                );
                $stmt->bind_param('sssss', $name, $email, $phone, $hash, $role);

                if ($stmt->execute()) {
                    $success = true;
                    $registeredName = $name;

                    // Tell every active admin that a new patient signed up (shows in the admin bell).
                    $patientMsg = $name . ' has registered as a new patient.';
                    $notifyAdmins = $conn->prepare(
                        "INSERT INTO notifications (user_id, notification_type, title, message, is_read, created_at)
                         SELECT user_id, 'GENERAL', 'New Patient Registration', ?, FALSE, NOW()
                         FROM users WHERE role = 'ADMIN' AND is_active = TRUE"
                    );
                    if ($notifyAdmins) {
                        $notifyAdmins->bind_param('s', $patientMsg);
                        $notifyAdmins->execute();
                    }
                } else {
                    $error = 'Registration failed. Please try again.';
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MediVault - Sign up</title>
    <link rel="stylesheet" href="login.css">
</head>

<body>

<div class="page">

    <section class="brand-panel">

        <div class="brand">
            <span class="brand-icon">+</span>
            <span>MediVault</span>
        </div>

        <div class="brand-copy">

            <h1>
                Your campus<br>
                pharmacy.
            </h1>

            <p>
                Create your MediVault account to browse medicines,
                reserve pickup, upload prescriptions and place orders.
            </p>

            <ul>
                <li>💊 Drug interaction detection</li>
                <li>📋 Pharmacist-approved prescriptions</li>
                <li>🔔 Automatic refill reminders</li>
            </ul>

        </div>

    </section>

    <section class="login-panel">

        <div class="login-box">

            <h2>Sign up</h2>

            <div class="subtitle">
                Create your MediVault account
            </div>

            <?php if ($error): ?>

                <div class="error auto-hide-error">
                    <?= htmlspecialchars($error) ?>
                </div>

            <?php endif; ?>

            <form method="post" action="register.php">

                <label for="full_name">
                    Full name:
                </label>

                <input
                    id="full_name"
                    name="full_name"
                    type="text"
                    placeholder="Your full name"
                    value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"
                    required
                >

                <label for="email">
                    Email address:
                </label>

                <input
                    id="email"
                    name="email"
                    type="email"
                    placeholder="you@gmail.com"
                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                    required
                >

                <label for="phone">
                    Phone:
                </label>

                <input
                    id="phone"
                    name="phone"
                    type="text"
                    placeholder="01XXXXXXXXX"
                    value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                    required
                >

                <label for="password">
                    Password:
                </label>

                <input
                    id="password"
                    name="password"
                    type="password"
                    placeholder="Minimum 6 characters"
                    required
                >

                <label for="role">
                    Register as:
                </label>

                <select id="role" name="role" class="role-select" required>

                    <option value="">
                        Select user type
                    </option>

                    <option
                        value="PATIENT"
                        <?= (($_POST['role'] ?? '') === 'PATIENT') ? 'selected' : '' ?>
                    >
                        Patient
                    </option>

                    <option
                        value="PHARMACIST"
                        <?= (($_POST['role'] ?? '') === 'PHARMACIST') ? 'selected' : '' ?>
                    >
                        Pharmacist
                    </option>
</select>

                <div id="pharmacyField" style="display: <?= (($_POST['role'] ?? '') === 'PHARMACIST') ? 'block' : 'none' ?>;">
                    <label for="pharmacy_id">Applying for branch:</label>
                    <select id="pharmacy_id" name="pharmacy_id" class="role-select" <?= (($_POST['role'] ?? '') === 'PHARMACIST') ? 'required' : '' ?>>
                        <option value="">Select pharmacy branch</option>
                        <?php
                        $pharmacyOptions = $conn->query("SELECT pharmacy_id, pharmacy_name FROM pharmacies WHERE is_active = TRUE ORDER BY pharmacy_name");
                        if ($pharmacyOptions):
                            while ($branch = $pharmacyOptions->fetch_assoc()):
                        ?>
                            <option value="<?= (int)$branch['pharmacy_id'] ?>" <?= ((string)($_POST['pharmacy_id'] ?? '') === (string)$branch['pharmacy_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($branch['pharmacy_name']) ?>
                            </option>
                        <?php endwhile; endif; ?>
                        <option value="OTHER" <?= (($_POST['pharmacy_id'] ?? '') === 'OTHER') ? 'selected' : '' ?>>Others</option>
                    </select>

                    <div id="otherPharmacyField" class="other-pharmacy-field" style="display: <?= (($_POST['pharmacy_id'] ?? '') === 'OTHER') ? 'block' : 'none' ?>;">
                        <label for="other_pharmacy_name">Pharmacy / company name:</label>
                        <input
                            id="other_pharmacy_name"
                            name="other_pharmacy_name"
                            type="text"
                            placeholder="Enter your pharmacy or company name"
                            value="<?= htmlspecialchars($_POST['other_pharmacy_name'] ?? '') ?>"
                            maxlength="150"
                        >
                    </div>
                </div>

                <button
                    class="login-btn"
                    type="submit"
                >
                    Create account
                </button>

            </form>

            <div class="signup">
                Already have an account?
                <a href="login.php">Log in</a>
            </div>

        </div>

    </section>

</div>

<?php if ($success): ?>

<div class="success-overlay">

    <div class="success-popup">

        <div class="success-icon">
            ✓
        </div>

        <h3>
            Registration Successful
        </h3>

        <p>
            Welcome,
            <strong><?= htmlspecialchars($registeredName) ?></strong>
        </p>

        <span>
            <?php if (($_POST['role'] ?? '') === 'PHARMACIST'): ?>
                Your pharmacist registration has been submitted. An admin must approve it before you can log in.
            <?php else: ?>
                Your account has been created successfully.
            <?php endif; ?>
        </span>

        <div class="redirect-text">
            Redirecting to login...
        </div>

    </div>

</div>

<script>
    setTimeout(function () {
        window.location.href = "login.php";
    }, 3000);
</script>

<?php endif; ?>

<script>
    const roleSelect = document.getElementById('role');
    const pharmacyField = document.getElementById('pharmacyField');
    const pharmacySelect = document.getElementById('pharmacy_id');
    const otherPharmacyField = document.getElementById('otherPharmacyField');
    const otherPharmacyName = document.getElementById('other_pharmacy_name');

    function updatePharmacyFields() {
        if (!roleSelect || !pharmacyField || !pharmacySelect) return;
        const isPharmacist = roleSelect.value === 'PHARMACIST';
        const isOther = isPharmacist && pharmacySelect.value === 'OTHER';

        pharmacyField.style.display = isPharmacist ? 'block' : 'none';
        pharmacySelect.required = isPharmacist;
        if (otherPharmacyField) otherPharmacyField.style.display = isOther ? 'block' : 'none';
        if (otherPharmacyName) otherPharmacyName.required = isOther;

        if (!isPharmacist) {
            pharmacySelect.value = '';
            if (otherPharmacyName) {
                otherPharmacyName.value = '';
                otherPharmacyName.required = false;
            }
        }
    }

    if (roleSelect) roleSelect.addEventListener('change', updatePharmacyFields);
    if (pharmacySelect) pharmacySelect.addEventListener('change', updatePharmacyFields);
    updatePharmacyFields();

    const errorBox = document.querySelector('.auto-hide-error');

    if (errorBox) {

        setTimeout(function () {

            errorBox.style.opacity = '0';

            setTimeout(function () {
                errorBox.remove();
            }, 300);

        }, 2000);
    }

</script>

</body>
</html>
