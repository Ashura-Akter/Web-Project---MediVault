<?php
session_start();
require_once __DIR__ . '/../database/connection.php';
require_once __DIR__ . '/../database/mailer.php';

$error = '';
$success = '';
$invalidOtp = false;
$step = $_SESSION['reset_step'] ?? 'email';
$resetEmail = $_SESSION['reset_email'] ?? '';

function clear_reset_session(): void {
    unset($_SESSION['reset_step'], $_SESSION['reset_email'], $_SESSION['reset_user_id'], $_SESSION['reset_dev_otp']);
}

// "Use a different email" link: start over.
if (isset($_GET['restart'])) {
    clear_reset_session();
    header('Location: forgot_password.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'send_otp') {
        $resetEmail = trim($_POST['email'] ?? '');
        $stmt = $conn->prepare("SELECT user_id, full_name FROM users WHERE email = ? AND is_active = TRUE LIMIT 1");
        $stmt->bind_param('s', $resetEmail);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user) {
            $error = 'No active account is registered with this email address.';
            $step = 'email';
        } else {
            $otp = (string)random_int(100000, 999999);
            $otpHash = password_hash($otp, PASSWORD_DEFAULT);
            $expiresAt = date('Y-m-d H:i:s', time() + 600);

            $delete = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
            $delete->bind_param('i', $user['user_id']);
            $delete->execute();

            $save = $conn->prepare(
                "INSERT INTO password_resets (user_id, email, otp_hash, expires_at)
                 VALUES (?, ?, ?, ?)"
            );
            $save->bind_param('isss', $user['user_id'], $resetEmail, $otpHash, $expiresAt);
            $save->execute();

            $subject = 'MediVault Password Reset OTP';
            $message = "Hello {$user['full_name']},\n\nYour MediVault password reset OTP is: {$otp}\n\nThis OTP will expire in 10 minutes.\nIf you did not request a password reset, you can ignore this email.\n\nMediVault";

            $mail = mv_send_mail($resetEmail, $subject, $message);
            $cfg = mv_mail_config();

            if ($mail['ok'] || !empty($cfg['dev_show_otp'])) {
                $_SESSION['reset_step'] = 'otp';
                $_SESSION['reset_email'] = $resetEmail;
                $_SESSION['reset_user_id'] = (int)$user['user_id'];
                $step = 'otp';
                if ($mail['ok']) {
                    unset($_SESSION['reset_dev_otp']);
                    $success = 'OTP has been sent to your registered email address.';
                } else {
                    // Local/XAMPP fallback: email is not configured, so show the OTP here.
                    $_SESSION['reset_dev_otp'] = $otp;
                    mv_mail_log('OTP for ' . $resetEmail . ' = ' . $otp);
                    $success = 'Email could not be sent from this server, so the OTP is shown below (local testing mode).';
                }
            } else {
                $error = 'OTP could not be sent. Please check the mail settings in database/mail_config.php.';
                $step = 'email';
            }
        }
    } elseif ($action === 'verify_otp') {
        $resetEmail = $_SESSION['reset_email'] ?? trim($_POST['email'] ?? '');
        $otp = trim($_POST['otp'] ?? '');
        $userId = (int)($_SESSION['reset_user_id'] ?? 0);

        $stmt = $conn->prepare(
            "SELECT reset_id, otp_hash, expires_at
             FROM password_resets
             WHERE user_id = ? AND email = ? AND verified_at IS NULL
             ORDER BY reset_id DESC LIMIT 1"
        );
        $stmt->bind_param('is', $userId, $resetEmail);
        $stmt->execute();
        $reset = $stmt->get_result()->fetch_assoc();

        if (!$reset || strtotime($reset['expires_at']) < time() || !password_verify($otp, $reset['otp_hash'])) {
            $invalidOtp = true;
            $step = 'otp';
        } else {
            $verify = $conn->prepare("UPDATE password_resets SET verified_at = NOW() WHERE reset_id = ?");
            $verify->bind_param('i', $reset['reset_id']);
            $verify->execute();
            unset($_SESSION['reset_dev_otp']);
            $_SESSION['reset_step'] = 'password';
            $step = 'password';
            $success = 'OTP verified. You can now set a new password.';
        }
    } elseif ($action === 'set_password') {
        $userId = (int)($_SESSION['reset_user_id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($userId <= 0 || ($_SESSION['reset_step'] ?? '') !== 'password') {
            $error = 'Please request a new OTP first.';
            $step = 'email';
            clear_reset_session();
        } elseif (strlen($newPassword) < 6) {
            $error = 'Password must be at least 6 characters.';
            $step = 'password';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Passwords do not match.';
            $step = 'password';
        } else {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ? AND is_active = TRUE");
            $stmt->bind_param('si', $hash, $userId);
            $stmt->execute();

            $delete = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
            $delete->bind_param('i', $userId);
            $delete->execute();

            clear_reset_session();
            $_SESSION['login_email'] = $resetEmail;
            $_SESSION['login_error'] = 'Password changed successfully. Please log in with your new password.';
            header('Location: login.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MediVault - Forgot Password</title>
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
            <h1>Reset your<br>password.</h1>
            <p>Verify your registered email with an OTP and create a new password.</p>
        </div>
    </section>

    <section class="login-panel">
        <div class="login-box">
            <h2>Forgot password</h2>
            <div class="subtitle">Password recovery</div>

            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="success" style="background:#e7f7f3;color:#087f77;padding:10px;border-radius:10px;margin-bottom:14px;">
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($step === 'email'): ?>
                <form method="post">
                    <label for="email">Registered email address:</label>
                    <input id="email" name="email" type="email" value="<?= htmlspecialchars($resetEmail) ?>" placeholder="you@gmail.com" required>
                    <button class="login-btn" type="submit" name="action" value="send_otp">Send OTP</button>
                </form>

            <?php elseif ($step === 'otp'): ?>
                <?php if (!empty($_SESSION['reset_dev_otp'])): ?>
                    <div style="background:#fff8e6;border:1px dashed #f0b429;color:#7a5200;padding:10px;border-radius:10px;margin-bottom:14px;text-align:center;">
                        Your OTP: <strong style="font-size:20px;letter-spacing:4px;"><?= htmlspecialchars($_SESSION['reset_dev_otp']) ?></strong>
                    </div>
                <?php endif; ?>
                <form method="post">
                    <label for="email">Registered email address:</label>
                    <input id="email" name="email" type="email" value="<?= htmlspecialchars($resetEmail) ?>" readonly>

                    <label for="otp">Enter OTP:</label>
                    <input id="otp" name="otp" type="text" inputmode="numeric" maxlength="6" placeholder="6-digit OTP" required>

                    <button class="login-btn" type="submit" name="action" value="verify_otp">Verify OTP</button>
                </form>
                <div class="signup" style="margin-top:10px"><a href="forgot_password.php?restart=1">Use a different email / resend OTP</a></div>

            <?php else: ?>
                <form method="post">
                    <label for="email">Registered email address:</label>
                    <input id="email" type="email" value="<?= htmlspecialchars($resetEmail) ?>" readonly>

                    <label for="new_password">New password:</label>
                    <input id="new_password" name="new_password" type="password" placeholder="Minimum 6 characters" required>

                    <label for="confirm_password">Confirm new password:</label>
                    <input id="confirm_password" name="confirm_password" type="password" placeholder="Re-enter password" required>

                    <button class="login-btn" type="submit" name="action" value="set_password">Set New Password</button>
                </form>
            <?php endif; ?>

            <div class="signup">
                <a href="login.php">Back to login</a>
            </div>
        </div>
    </section>
</div>

<?php if ($invalidOtp): ?>
<div class="success-overlay" style="display:flex;">
    <div class="success-popup">
        <div class="success-icon" style="background:#ef4444;">!</div>
        <h3>Invalid OTP</h3>
        <p>The OTP is invalid or has expired.</p>
        <button class="login-btn" type="button" onclick="this.closest('.success-overlay').remove()">Try Again</button>
    </div>
</div>
<?php endif; ?>
</body>
</html>
