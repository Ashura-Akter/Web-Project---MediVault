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

/*
 * Admin has its own support management section.
 * Patient/Pharmacist use this request form.
 */
if ($role === 'ADMIN') {
    header('Location: index.php?tab=support');
    exit;
}

$defaultEmail = $_SESSION['email'] ?? '';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (
        !filter_var($email, FILTER_VALIDATE_EMAIL)
        || $description === ''
    ) {

        $error = 'Please enter a valid email and describe the help you need.';
    } elseif (strlen($description) > 520) {

        $error = 'Please keep the description within 500 characters.';
    } else {

        $adminStmt = $conn->query(
            "SELECT user_id
             FROM users
             WHERE role='ADMIN'
             AND is_active=TRUE
             ORDER BY
                 (email='admin.medivult@gmail.com') DESC,
                 user_id
             LIMIT 1"
        );

        $admin = $adminStmt
            ? $adminStmt->fetch_assoc()
            : null;

        if (!$admin) {

            $error = 'No active administrator is available right now.';
        } else {

            $title = 'Help & Support Request';

            $msg =
                'From: ' . $email .
                ' | User ID: ' . $userId .
                ' | Role: ' . $role .
                ' | ' . $description;

            $stmt = $conn->prepare(
                "INSERT INTO notifications (user_id, notification_type, title, message, is_read, created_at)
                 SELECT user_id, 'GENERAL', ?, ?, FALSE, NOW()
                 FROM users WHERE role = 'ADMIN' AND is_active = TRUE"
            );
            $stmt->bind_param('ss', $title, $msg);

            if ($stmt->execute()) {

                $message =
                    'Your support request has been sent to the MediVault admin.';
            } else {

                $error = 'Could not submit your request.';
            }
        }
    }
}
?>

<?php require_once __DIR__ . '/../common/header.php'; ?>

<style>
    /*
 * IMPORTANT:
 * The left navigation is NOT recreated here.
 * common/header.php provides the same navigation
 * used by the patient pages.
 */

    .support-content {
        min-height: calc(100vh - 68px);

        display: flex;
        flex-direction: column;

        align-items: center;
        justify-content: center;

        padding: 34px 28px 30px;
    }

    /* .support-card {
    position: relative;

    width: min(800px, 100%);

    background: #fff;

    border-radius: 18px;

    padding: 32px;

    box-shadow:
        0 3px 12px rgba(0, 0, 0, .08);

    transition:
        box-shadow .3s ease,
        transform .3s ease;

    isolation: isolate;
}

.support-card:hover {
    transform: translateY(-2px);

    box-shadow:
        0 10px 28px rgba(0, 0, 0, .14),
        0 3px 10px rgba(7, 143, 134, .08);
}

.support-card:focus-within {
    box-shadow:
        0 10px 28px rgba(0, 0, 0, .12),
        0 0 0 1px rgba(7, 143, 134, .20);
} */



    .support-card {
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
    }

    /* Running gradient border */
    .support-card::before {
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
    .support-card:hover {
        transform: translateY(-2px);

        box-shadow:
            0 10px 28px rgba(0, 0, 0, .14),
            0 3px 10px rgba(7, 143, 134, .08);
    }

    /* Click/focus = continuously running border */
    .support-card:focus-within::before {
        opacity: 1;
        animation: supportBorderRun 2.2s linear infinite;
    }

    @keyframes supportBorderRun {
        0% {
            background-position: 0% 50%;
        }

        100% {
            background-position: 300% 50%;
        }
    }




    .support-title {
        margin: 0 0 8px;

        font-size: 28px;

        text-align: center;
    }

    .support-sub {
        color: #777;

        text-align: center;

        margin-bottom: 22px;
    }

    .support-field {
        margin-bottom: 16px;
    }

    .support-field label {
        display: block;

        font-weight: 700;

        font-size: 13px;

        margin-bottom: 7px;
    }

    .support-field input,
    .support-field textarea {
        width: 100%;

        padding: 13px;

        border: 1px solid #d6d6d6;

        border-radius: 10px;

        font-size: 14px;

        outline: none;
    }

    .support-field input:focus,
    .support-field textarea:focus {
        border-color: #078f86;

        box-shadow:
            0 0 0 2px rgba(7, 143, 134, .10);
    }

    .support-field textarea {
        min-height: 180px;

        resize: vertical;
    }

    .support-readonly {
        background: #f5f5f5;
    }

    .support-actions {
        display: flex;

        justify-content: center;
        align-items: center;

        gap: 10px;

        margin-top: 18px;
    }

    .support-btn {
        border: 0;

        border-radius: 22px;

        padding: 12px 23px;

        font-weight: 700;

        cursor: pointer;

        text-decoration: none;

        display: inline-block;
    }

    .support-btn.primary {
        background: #078f86;
        color: #fff;
    }

    .support-btn.back {
        background: #eee;
        color: #222;
    }

    .support-notice {
        padding: 12px;

        border-radius: 10px;

        margin-bottom: 16px;

        background: #e6f7f3;

        color: #087f77;
    }

    .support-notice.error {
        background: #ffe3e0;

        color: #a43229;
    }

    /*
 * Admin Contact stays OUTSIDE the main card
 * and remains centered.
 */
    .admin-contact {
        text-align: center;

        margin-top: 22px;

        color: #555;
    }

    .admin-contact h3 {
        margin: 0 0 7px;

        color: #222;
    }

    .admin-contact p {
        margin: 4px 0;
    }

    @media (max-width: 760px) {

        .support-content {
            padding: 24px 12px 28px;
        }

        .support-card {
            padding: 24px 18px;
        }
    }
</style>

<main class="content support-content">

    <div class="support-card">

        <h1 class="support-title">
            Help &amp; Support
        </h1>

        <div class="support-sub">
            Tell our administrator what you need help with.
        </div>

        <?php if ($message): ?>

            <div class="support-notice">
                <?= htmlspecialchars($message) ?>
            </div>

        <?php endif; ?>

        <?php if ($error): ?>

            <div class="support-notice error">
                <?= htmlspecialchars($error) ?>
            </div>

        <?php endif; ?>

        <form method="post">

            <div class="support-field">

                <label>Email</label>

                <input
                    class="support-readonly"
                    type="email"
                    name="email"
                    value="<?= htmlspecialchars($defaultEmail) ?>"
                    readonly>

            </div>

            <div class="support-field">

                <label>How can we help?</label>

                <textarea
                    name="description"
                    maxlength="500"
                    placeholder="Describe your issue or request in detail..."
                    required><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>

            </div>

            <div class="support-actions">

                <button
                    class="support-btn primary"
                    type="submit">
                    Submit
                </button>

                <a
                    class="support-btn back"
                    href="../patient/browse.php">
                    Back
                </a>

            </div>

        </form>

    </div>

    <div class="admin-contact">

        <h3>Admin Contact</h3>

        <p>
            <strong>Email:</strong>
            admin.medivult@gmail.com
        </p>

        <p>
            <strong>Phone:</strong>
            01700000000
        </p>

    </div>

</main>

</div>
</body>

</html>