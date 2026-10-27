<?php
session_start();
require_once __DIR__ . '/../database/connection.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login/login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$role = strtoupper($_SESSION['role'] ?? '');

if (!in_array($role, ['PATIENT', 'PHARMACIST', 'ADMIN'], true)) {
    header('Location: ../login/login.php');
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $dob = trim($_POST['date_of_birth'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';

    if ($name === '' || $email === '' || $phone === '') {
        $error = 'Name, email and phone are required.';
    } else {
        $check = $conn->prepare(
            "SELECT user_id FROM users 
             WHERE email = ? AND user_id <> ? 
             LIMIT 1"
        );
        $check->bind_param('si', $email, $userId);
        $check->execute();

        if ($check->get_result()->fetch_assoc()) {
            $error = 'That email is already used by another account.';
        } else {
            if ($newPassword !== '' && strlen($newPassword) < 6) {
                $error = 'New password must be at least 6 characters.';
            } else {

                if ($newPassword !== '') {
                    $hash = password_hash($newPassword, PASSWORD_DEFAULT);

                    $stmt = $conn->prepare(
                        "UPDATE users 
                         SET full_name=?, 
                             email=?, 
                             phone=?, 
                             date_of_birth=NULLIF(?, ''), 
                             address=?, 
                             password_hash=? 
                         WHERE user_id=?"
                    );

                    $stmt->bind_param(
                        'ssssssi',
                        $name,
                        $email,
                        $phone,
                        $dob,
                        $address,
                        $hash,
                        $userId
                    );
                } else {
                    $stmt = $conn->prepare(
                        "UPDATE users 
                         SET full_name=?, 
                             email=?, 
                             phone=?, 
                             date_of_birth=NULLIF(?, ''), 
                             address=? 
                         WHERE user_id=?"
                    );

                    $stmt->bind_param(
                        'sssssi',
                        $name,
                        $email,
                        $phone,
                        $dob,
                        $address,
                        $userId
                    );
                }

                if ($stmt->execute()) {
                    $_SESSION['full_name'] = $name;
                    $_SESSION['email'] = $email;

                    $message = 'Profile updated successfully.';
                } else {
                    $error = 'Could not update the profile.';
                }
            }
        }
    }
}

$stmt = $conn->prepare(
    "SELECT user_id, full_name, email, phone, date_of_birth, address, role 
     FROM users 
     WHERE user_id=? AND is_active=TRUE 
     LIMIT 1"
);

$stmt->bind_param('i', $userId);
$stmt->execute();

$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    session_destroy();
    header('Location: ../login/login.php');
    exit;
}

$profile = null;

if ($role === 'PHARMACIST') {
    $stmt = $conn->prepare(
        "SELECT 
            pp.license_no,
            pp.approval_status,
            pp.pharmacy_or_company_name,
            p.pharmacy_name,
            p.campus_name,
            p.address AS pharmacy_address,
            p.phone AS pharmacy_phone,
            p.email AS pharmacy_email
         FROM pharmacist_profiles pp
         LEFT JOIN pharmacies p 
            ON p.pharmacy_id = pp.pharmacy_id
         WHERE pp.user_id=?
         LIMIT 1"
    );

    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $profile = $stmt->get_result()->fetch_assoc();
}

$back = $role === 'PHARMACIST'
    ? '../pharmasist/medivaultpharmasist.php'
    : (
        $role === 'ADMIN'
        ? '../admin/index.php'
        : '../patient/browse.php'
    );
?>

<?php require_once __DIR__ . '/../common/header.php'; ?>

<style>
    /* Sidebar/navigation comes from common/header.php.
   No separate sidebar is created on this page. */

    .profile-content {
        min-height: calc(100vh - 68px);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 34px 28px 42px;
    }

    /* .profile-card {
    position: relative;
    width: min(800px, 100%);
    background: #fff;
    border-radius: 18px;
    padding: 32px;
    box-shadow: 0 3px 12px rgba(0, 0, 0, .08);
    transition:
        box-shadow .3s ease,
        transform .3s ease;
    isolation: isolate;
    margin-left: -140px;
}

.profile-card:hover {
    transform: translateY(-2px);
    box-shadow:
        0 10px 28px rgba(0, 0, 0, .14),
        0 3px 10px rgba(7, 143, 134, .08);
}

.profile-card:focus-within {
    box-shadow:
        0 10px 28px rgba(0, 0, 0, .12),
        0 0 0 1px rgba(7, 143, 134, .20);
} */


    .profile-card {
        position: relative;
        width: min(800px, 100%);
        background: #fff;
        border-radius: 18px;
        padding: 32px;
        box-shadow: 0 3px 12px rgba(0, 0, 0, .08);
        transition:
            box-shadow .3s ease,
            transform .3s ease;
        isolation: isolate;
        margin-left: -140px;
    }

    /* Running gradient border */
    .profile-card::before {
        content: "";
        position: absolute;
        inset: -1px;
        border-radius: 19px;
        padding: 1.5px;

        background: linear-gradient(90deg,
                #078f86,
                #7de3da,
                #ffffff,
                #7de3da,
                #078f86);

        background-size: 300% 100%;

        -webkit-mask:
            linear-gradient(#fff 0 0) content-box,
            linear-gradient(#fff 0 0);

        -webkit-mask-composite: xor;
        mask-composite: exclude;

        opacity: 0;
        pointer-events: none;

        z-index: -1;
    }

    /* Normal mouse hover shadow */
    .profile-card:hover {
        transform: translateY(-2px);

        box-shadow:
            0 10px 28px rgba(0, 0, 0, .14),
            0 3px 10px rgba(7, 143, 134, .08);
    }

    /* Click/focus = continuously running border */
    .profile-card:focus-within::before {
        opacity: 1;
        animation: profileBorderRun 2.2s linear infinite;
    }

    @keyframes profileBorderRun {
        0% {
            background-position: 0% 50%;
        }

        100% {
            background-position: 300% 50%;
        }
    }




    .profile-card h1 {
        margin: 0 0 7px;
        font-size: 28px;
        padding: 0;
        text-align: center;
    }

    .profile-sub {
        color: #777;
        margin-bottom: 22px;
        text-align: center;
    }

    .profile-notice {
        padding: 11px 14px;
        border-radius: 10px;
        background: #e4f7f3;
        color: #087f77;
        margin-bottom: 15px;
    }

    .profile-notice.error {
        background: #ffe4e1;
        color: #a83228;
    }

    .profile-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }

    .profile-field label {
        display: block;
        font-size: 13px;
        font-weight: 700;
        margin-bottom: 7px;
    }

    .profile-field input,
    .profile-field textarea {
        width: 100%;
        padding: 12px;
        border: 1px solid #d6d6d6;
        border-radius: 10px;
        font-size: 14px;
        outline: none;
    }

    .profile-field input:focus,
    .profile-field textarea:focus {
        border-color: #078f86;
        box-shadow: 0 0 0 2px rgba(7, 143, 134, .10);
    }

    .profile-field textarea {
        min-height: 90px;
        resize: vertical;
    }

    .profile-full {
        grid-column: 1 / -1;
    }

    .profile-section {
        margin-top: 22px;
        border-top: 1px solid #eee;
        padding-top: 20px;
    }

    .profile-section h3 {
        margin: 0 0 12px;
    }

    .profile-info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }

    .profile-info {
        background: #f7f7f7;
        border-radius: 10px;
        padding: 12px;
    }

    .profile-info small {
        display: block;
        color: #777;
        margin-bottom: 3px;
    }

    .profile-info strong {
        font-size: 14px;
    }

    .profile-actions {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 10px;
        margin-top: 22px;
    }

    .profile-btn {
        padding: 11px 20px;
        border: 0;
        border-radius: 22px;
        font-weight: 700;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
    }

    .profile-btn.primary {
        background: #078f86;
        color: #fff;
    }

    .profile-btn.secondary {
        background: #eee;
        color: #222;
    }

    @media (max-width: 760px) {

        .profile-grid,
        .profile-info-grid {
            grid-template-columns: 1fr;
        }

        .profile-full {
            grid-column: auto;
        }

        .profile-content {
            padding: 24px 12px 34px;
        }

        .profile-card {
            padding: 24px 18px;
        }
    }
</style>

<main class="content profile-content">

    <div class="profile-card">

        <h1>Profile</h1>

        <div class="profile-sub">
            View and update your account information.
        </div>

        <?php if ($message): ?>
            <div class="profile-notice">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="profile-notice error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="post">

            <div class="profile-grid">

                <div class="profile-field">
                    <label>Full name</label>
                    <input
                        name="full_name"
                        value="<?= htmlspecialchars($user['full_name']) ?>"
                        required>
                </div>

                <div class="profile-field">
                    <label>Email</label>
                    <input
                        type="email"
                        name="email"
                        value="<?= htmlspecialchars($user['email']) ?>"
                        required>
                </div>

                <div class="profile-field">
                    <label>Phone</label>
                    <input
                        name="phone"
                        value="<?= htmlspecialchars($user['phone']) ?>"
                        required>
                </div>

                <div class="profile-field">
                    <label>Date of birth</label>
                    <input
                        type="date"
                        name="date_of_birth"
                        value="<?= htmlspecialchars($user['date_of_birth'] ?? '') ?>">
                </div>

                <div class="profile-field profile-full">
                    <label>Address</label>
                    <textarea name="address"><?= htmlspecialchars($user['address'] ?? '') ?></textarea>
                </div>

            </div>

            <?php if ($role === 'PHARMACIST' && $profile): ?>

                <div class="profile-section">

                    <h3>Pharmacist information</h3>

                    <div class="profile-info-grid">

                        <div class="profile-info">
                            <small>License No.</small>
                            <strong>
                                <?= htmlspecialchars($profile['license_no'] ?? '-') ?>
                            </strong>
                        </div>

                        <div class="profile-info">
                            <small>Approval Status</small>
                            <strong>
                                <?= htmlspecialchars($profile['approval_status'] ?? '-') ?>
                            </strong>
                        </div>

                        <div class="profile-info">
                            <small>Pharmacy</small>
                            <strong>
                                <?= htmlspecialchars(
                                    $profile['pharmacy_name']
                                        ?? $profile['pharmacy_or_company_name']
                                        ?? '-'
                                ) ?>
                            </strong>
                        </div>

                        <div class="profile-info">
                            <small>Campus</small>
                            <strong>
                                <?= htmlspecialchars($profile['campus_name'] ?? '-') ?>
                            </strong>
                        </div>

                    </div>

                </div>

            <?php endif; ?>

            <div class="profile-section">

                <h3>Change password</h3>

                <div class="profile-field">

                    <label>
                        New password (leave blank to keep current password)
                    </label>

                    <input
                        type="password"
                        name="new_password"
                        minlength="6">

                </div>

            </div>

            <div class="profile-actions">

                <button
                    class="profile-btn primary"
                    type="submit">
                    Save Changes
                </button>

                <a
                    class="profile-btn secondary"
                    href="<?= htmlspecialchars($back) ?>">
                    Back
                </a>

            </div>

        </form>

    </div>

</main>

</div>
</body>

</html>