<?php

session_start();

if (isset($_SESSION['user_id'])) {
    $currentRole = strtoupper($_SESSION['role'] ?? '');

    if ($currentRole === 'PATIENT') {
        header('Location: ../patient/browse.php');
    } elseif ($currentRole === 'PHARMACIST') {
        header('Location: ../pharmasist/medivaultpharmasist.php');
    } else {
        header('Location: ../common/home.php');
    }
    exit;
}

$error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);

$email = $_SESSION['login_email'] ?? '';
unset($_SESSION['login_email']);

$selectedRole = $_SESSION['login_role'] ?? '';
unset($_SESSION['login_role']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MediVault - Log in</title>
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
            <h1>Your campus<br>pharmacy.</h1>

            <p>
                Browse medicines, reserve for pickup, upload
                prescriptions, and get drug interaction warnings —
                all in one place.
            </p>

            <ul>
                <li>💊 Drug interaction detection across your order history</li>
                <li>📋 Pharmacist-approved prescription reservations</li>
                <li>🔔 Automatic refill reminders based on past orders</li>
            </ul>
        </div>
    </section>

    <section class="login-panel">
        <div class="login-box">

            <h2>Log in</h2>

            <div class="subtitle">
                Campus Health Centre · 2026
            </div>

            <?php if ($error): ?>
                <div class="error auto-hide-error">
                    <span class="error-icon">⚠</span>
                    <span class="error-msg"><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form action="authenticate.php" method="post" id="loginForm">

                <label for="email">
                    Email address:
                </label>

                <input
                    id="email"
                    name="email"
                    type="email"
                    value="<?= htmlspecialchars($email) ?>"
                    placeholder="you@gmail.com"
                    required
                >

                <label for="password">
                    Password:
                </label>

                <div class="password-wrap">
                    <input
                        id="password"
                        name="password"
                        type="password"
                        placeholder="••••••••••"
                        required
                    >

                    <button
                        type="button"
                        class="eye"
                        onclick="togglePassword()"
                        aria-label="Show password"
                    >
                        ◉
                    </button>
                </div>

                <label for="role">
                    Login as:
                </label>

                <select
                    id="role"
                    name="role"
                    class="role-select"
                    required
                >
                    <option value="">
                        Select user type
                    </option>

                    <option
                        value="PATIENT"
                        <?= $selectedRole === 'PATIENT' ? 'selected' : '' ?>
                    >
                        Patient
                    </option>

                    <option
                        value="PHARMACIST"
                        <?= $selectedRole === 'PHARMACIST' ? 'selected' : '' ?>
                    >
                        Pharmacist
                    </option>

                    <option
                        value="ADMIN"
                        <?= $selectedRole === 'ADMIN' ? 'selected' : '' ?>
                    >
                        Admin
                    </option>
                </select>

                <button
                    class="login-btn"
                    type="submit"
                >
                    Log in
                </button>

            </form>

            <div class="signup" style="margin-bottom: 12px;">
                <a href="forgot_password.php?restart=1">Forgot password?</a>
            </div>

            <div class="signup">
                Don't have account?
                <a href="register.php">
                    Sign up
                </a>
            </div>

        </div>
    </section>

</div>

<!-- ============================================================
     Welcome animation overlay
     Plays when the user clicks "Log in", then the form is submitted.
     Greeting text:
       - Patient     -> "Welcome"
       - Pharmacist  -> "Welcome Pharmacist"
       - Admin       -> "Welcome Admin"
     ============================================================ -->
<div class="welcome-overlay" id="welcomeOverlay" aria-hidden="true">
    <div class="welcome-card">

        <!-- Capsule that splits open -->
        <div class="capsule">
            <div class="capsule-top">
                <span class="capsule-shine"></span>
            </div>
            <div class="capsule-bottom">
                <span class="capsule-shine"></span>
            </div>

            <!-- Floating mini pills -->
            <span class="mini-pill mini-pill-1"></span>
            <span class="mini-pill mini-pill-2"></span>
            <span class="mini-pill mini-pill-3"></span>
            <span class="mini-pill mini-pill-4"></span>
        </div>

        <div class="welcome-text" id="welcomeText">Welcome</div>
        <div class="welcome-sub">Signing you in…</div>
    </div>
</div>

<!-- ============================================================
     ERROR animation overlay — plays on wrong login attempt
     (triggered by PHP rendering .auto-hide-error on page load,
     or live via JS when fields are invalid/incorrect)
     ============================================================ -->
<div class="error-overlay" id="errorOverlay" aria-hidden="true">
    <div class="error-card">
        <div class="error-capsule">
            <div class="error-capsule-half error-capsule-left">
                <span class="error-cross">✕</span>
            </div>
            <div class="error-capsule-half error-capsule-right">
                <span class="error-cross">✕</span>
            </div>
        </div>
        <div class="error-title" id="errorTitle">Login Failed</div>
        <div class="error-text" id="errorText">Please check your details and try again.</div>
    </div>
</div>

<style>
/* ---- Welcome animation overlay ---- */
.welcome-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    backdrop-filter: blur(3px);
    -webkit-backdrop-filter: blur(3px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 2147483600;
    opacity: 0;
    transition: opacity 0.28s ease;
}
.welcome-overlay.is-open {
    display: flex;
    opacity: 1;
}

.welcome-card {
    background: #ffffff;
    border-radius: 28px;
    padding: 34px 46px 30px;
    text-align: center;
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.28);
    transform: scale(0.85);
    opacity: 0;
    animation: welcomePop 0.45s cubic-bezier(0.22, 1, 0.36, 1) forwards;
}
@keyframes welcomePop {
    0%   { transform: scale(0.85); opacity: 0; }
    60%  { transform: scale(1.04); opacity: 1; }
    100% { transform: scale(1);    opacity: 1; }
}

/* ---- Capsule (pill) ---- */
.capsule {
    position: relative;
    width: 96px;
    height: 96px;
    margin: 0 auto 14px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.capsule-top,
.capsule-bottom {
    width: 74px;
    height: 40px;
    border-radius: 40px 40px 8px 8px;
    position: relative;
    overflow: hidden;
    box-shadow: 0 6px 14px rgba(6, 95, 70, 0.18);
}
.capsule-top {
    background: linear-gradient(160deg, #34d399 0%, #10b981 60%, #059669 100%);
    border-bottom-left-radius: 4px;
    border-bottom-right-radius: 4px;
    z-index: 2;
    animation: capsuleTopFly 0.9s 0.35s cubic-bezier(0.34, 1.4, 0.64, 1) forwards;
}
.capsule-bottom {
    background: linear-gradient(160deg, #ecfdf5 0%, #d1fae5 100%);
    border-top-left-radius: 4px;
    border-top-right-radius: 4px;
    border: 2px solid #a7f3d0;
    border-top: none;
    margin-top: -6px;
    z-index: 1;
    animation: capsuleBottomDrop 0.9s 0.35s cubic-bezier(0.34, 1.4, 0.64, 1) forwards;
}
.capsule-shine {
    position: absolute;
    top: 6px;
    left: 12px;
    width: 30px;
    height: 8px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.55);
    filter: blur(1px);
    animation: shinePulse 1.6s ease-in-out infinite;
}
.capsule-bottom .capsule-shine {
    background: rgba(52, 211, 153, 0.35);
    top: 8px;
}
@keyframes shinePulse {
    0%, 100% { opacity: 0.4; transform: translateX(0); }
    50%      { opacity: 0.9; transform: translateX(6px); }
}

@keyframes capsuleTopFly {
    0%   { transform: translateY(0) rotate(0deg); }
    60%  { transform: translateY(-22px) rotate(-14deg); opacity: 1; }
    100% { transform: translateY(-30px) rotate(-20deg); opacity: 0.85; }
}
@keyframes capsuleBottomDrop {
    0%   { transform: translateY(0) rotate(0deg); }
    60%  { transform: translateY(10px) rotate(10deg); }
    100% { transform: translateY(16px) rotate(16deg); }
}

/* ---- Floating mini pills ---- */
.mini-pill {
    position: absolute;
    width: 14px;
    height: 7px;
    border-radius: 10px;
    background: #10b981;
    opacity: 0;
    animation: miniPillFloat 1.1s 0.55s ease-out forwards;
}
.mini-pill-1 { top: 10px;  left: -4px;  background: #34d399; }
.mini-pill-2 { top: 30px;  right: -8px; background: #6ee7b7; animation-delay: 0.62s; }
.mini-pill-3 { bottom: 6px; left: 6px; background: #a7f3d0; animation-delay: 0.70s; }
.mini-pill-4 { bottom: 14px; right: -2px; background: #10b981; animation-delay: 0.78s; }

@keyframes miniPillFloat {
    0%   { opacity: 0; transform: translateY(0) scale(0.6) rotate(0deg); }
    40%  { opacity: 1; }
    100% { opacity: 0; transform: translateY(-26px) scale(1) rotate(35deg); }
}

/* "Welcome" text fades in with a soft upward slide. */
.welcome-text {
    font-size: 26px;
    font-weight: 800;
    color: #065f46;
    letter-spacing: 0.3px;
    opacity: 0;
    transform: translateY(6px);
    animation: welcomeText 0.4s 0.55s ease-out forwards;
}
@keyframes welcomeText {
    to { opacity: 1; transform: translateY(0); }
}
.welcome-sub {
    font-size: 13px;
    color: #64748b;
    font-weight: 600;
    margin-top: 4px;
    opacity: 0;
    animation: welcomeSub 0.35s 0.85s ease-out forwards;
}
@keyframes welcomeSub {
    to { opacity: 1; }
}

/* ============================================================
   ERROR ANIMATION STYLES
   ============================================================ */

/* ---- Inline error box (PHP-rendered) ---- */
.error {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #fef2f2;
    border: 1.5px solid #fecaca;
    color: #b91c1c;
    padding: 12px 16px;
    border-radius: 14px;
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 16px;
    box-shadow: 0 4px 14px rgba(185, 28, 28, 0.08);
    animation: errorSlideIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) forwards;
    transition: opacity 0.3s ease;
}
@keyframes errorSlideIn {
    0%   { opacity: 0; transform: translateY(-10px) scale(0.96); }
    60%  { opacity: 1; transform: translateY(2px) scale(1.01); }
    100% { opacity: 1; transform: translateY(0) scale(1); }
}
.error-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: #b91c1c;
    color: #fff;
    font-size: 13px;
    font-weight: 900;
    flex-shrink: 0;
    animation: errorIconPulse 1.6s ease-in-out infinite;
}
@keyframes errorIconPulse {
    0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(185, 28, 28, 0.5); }
    50%      { transform: scale(1.08); box-shadow: 0 0 0 6px rgba(185, 28, 28, 0); }
}

/* ---- Shake animation on the login box when error occurs ---- */
.login-box.shake {
    animation: loginShake 0.5s cubic-bezier(0.36, 0.07, 0.19, 0.97) both;
}
@keyframes loginShake {
    10%, 90% { transform: translateX(-2px); }
    20%, 80% { transform: translateX(4px); }
    30%, 50%, 70% { transform: translateX(-8px); }
    40%, 60% { transform: translateX(8px); }
}

/* ---- Full-screen error overlay (capsule broken) ---- */
.error-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    backdrop-filter: blur(3px);
    -webkit-backdrop-filter: blur(3px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 2147483600;
    opacity: 0;
    transition: opacity 0.28s ease;
}
.error-overlay.is-open {
    display: flex;
    opacity: 1;
}

.error-card {
    background: #ffffff;
    border-radius: 28px;
    padding: 34px 46px 30px;
    text-align: center;
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.28);
    border: 1.5px solid #fecaca;
    transform: scale(0.85);
    opacity: 0;
    animation: errorPop 0.45s cubic-bezier(0.22, 1, 0.36, 1) forwards;
}
@keyframes errorPop {
    0%   { transform: scale(0.85); opacity: 0; }
    60%  { transform: scale(1.05); opacity: 1; }
    100% { transform: scale(1);    opacity: 1; }
}

/* Broken capsule icon */
.error-capsule {
    position: relative;
    width: 96px;
    height: 96px;
    margin: 0 auto 14px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.error-capsule-half {
    width: 40px;
    height: 52px;
    border-radius: 20px;
    background: linear-gradient(160deg, #fca5a5 0%, #ef4444 60%, #dc2626 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 6px 14px rgba(220, 38, 38, 0.25);
    border: 2px solid #fecaca;
}
.error-capsule-left {
    border-top-right-radius: 6px;
    border-bottom-right-radius: 6px;
    margin-right: -4px;
    animation: errorLeftFly 0.9s 0.35s cubic-bezier(0.34, 1.4, 0.64, 1) forwards;
}
.error-capsule-right {
    border-top-left-radius: 6px;
    border-bottom-left-radius: 6px;
    margin-left: -4px;
    animation: errorRightFly 0.9s 0.35s cubic-bezier(0.34, 1.4, 0.64, 1) forwards;
}
@keyframes errorLeftFly {
    0%   { transform: translateX(0) rotate(0deg); }
    60%  { transform: translateX(-18px) rotate(-16deg); opacity: 1; }
    100% { transform: translateX(-26px) rotate(-24deg); opacity: 0.85; }
}
@keyframes errorRightFly {
    0%   { transform: translateX(0) rotate(0deg); }
    60%  { transform: translateX(18px) rotate(16deg); opacity: 1; }
    100% { transform: translateX(26px) rotate(24deg); opacity: 0.85; }
}
.error-cross {
    color: #ffffff;
    font-size: 22px;
    font-weight: 900;
    text-shadow: 0 1px 2px rgba(0,0,0,0.15);
    animation: crossPulse 1.2s ease-in-out infinite;
}
@keyframes crossPulse {
    0%, 100% { transform: scale(1); opacity: 0.9; }
    50%      { transform: scale(1.15); opacity: 1; }
}

.error-title {
    font-size: 24px;
    font-weight: 800;
    color: #b91c1c;
    letter-spacing: 0.3px;
    opacity: 0;
    transform: translateY(6px);
    animation: welcomeText 0.4s 0.55s ease-out forwards;
}
.error-text {
    font-size: 13px;
    color: #64748b;
    font-weight: 600;
    margin-top: 4px;
    max-width: 260px;
    opacity: 0;
    animation: welcomeSub 0.35s 0.85s ease-out forwards;
}
</style>

<script>

function togglePassword() {
    const password = document.getElementById('password');
    password.type = password.type === 'password'
        ? 'text'
        : 'password';
}

/* ---- Show error animation if PHP rendered an error on page load ---- */
(function () {
    var errorBox = document.querySelector('.auto-hide-error');

    if (errorBox) {
        // 1. Shake the login box
        var loginBox = document.querySelector('.login-box');
        if (loginBox) {
            loginBox.classList.add('shake');
            setTimeout(function () {
                loginBox.classList.remove('shake');
            }, 600);
        }

        // 2. Show the full-screen broken-capsule error overlay
        var errorOverlay = document.getElementById('errorOverlay');
        var errorTitleEl = document.getElementById('errorTitle');
        var errorTextEl  = document.getElementById('errorText');

        if (errorOverlay) {
            var msgText = errorBox.querySelector('.error-msg');
            var rawMsg = msgText ? msgText.textContent.trim() : 'Please check your details and try again.';

            if (errorTitleEl) {
                errorTitleEl.textContent = 'Login Failed';
            }
            if (errorTextEl) {
                errorTextEl.textContent = rawMsg || 'Please check your details and try again.';
            }

            errorOverlay.classList.add('is-open');
            errorOverlay.setAttribute('aria-hidden', 'false');

            // Auto-hide the overlay after ~2.2s
            setTimeout(function () {
                errorOverlay.classList.remove('is-open');
                errorOverlay.setAttribute('aria-hidden', 'true');
            }, 2200);
        }

        // 3. Fade out + remove the inline error box
        setTimeout(function () {
            errorBox.style.opacity = '0';
            setTimeout(function () {
                errorBox.remove();
            }, 300);
        }, 2000);
    }
})();

/* ---- Welcome animation on login submit ---- */
(function () {
    var form = document.getElementById('loginForm');
    var overlay = document.getElementById('welcomeOverlay');
    var textEl = document.getElementById('welcomeText');
    var errorOverlay = document.getElementById('errorOverlay');
    var errorTitleEl = document.getElementById('errorTitle');
    var errorTextEl  = document.getElementById('errorText');
    if (!form || !overlay) return;

    // ---- Client-side validation error animation ----
    function showFieldError(message) {
        // Shake the login box
        var loginBox = document.querySelector('.login-box');
        if (loginBox) {
            loginBox.classList.add('shake');
            setTimeout(function () {
                loginBox.classList.remove('shake');
            }, 600);
        }

        // Show the broken-capsule overlay
        if (errorOverlay) {
            if (errorTitleEl) errorTitleEl.textContent = 'Check Your Details';
            if (errorTextEl)  errorTextEl.textContent  = message;
            errorOverlay.classList.add('is-open');
            errorOverlay.setAttribute('aria-hidden', 'false');
            setTimeout(function () {
                errorOverlay.classList.remove('is-open');
                errorOverlay.setAttribute('aria-hidden', 'true');
            }, 2000);
        }
    }

    form.addEventListener('submit', function (e) {
        // Let the browser validate the fields first.
        if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
            // Trigger the error animation for invalid fields
            var invalid = form.querySelector(':invalid');
            var msg = 'Please fill in all fields correctly.';
            if (invalid) {
                if (invalid.id === 'email')         msg = 'Please enter a valid email address.';
                else if (invalid.id === 'password') msg = 'Please enter your password.';
                else if (invalid.id === 'role')     msg = 'Please select a user type.';
            }
            showFieldError(msg);
            return;
        }
        e.preventDefault();

        // Pick the greeting based on the selected role.
        //   Patient     -> "Welcome"
        //   Pharmacist  -> "Welcome Pharmacist"
        //   Admin       -> "Welcome Admin"
        var roleSel = document.getElementById('role');
        var role = roleSel ? roleSel.value.toUpperCase() : '';
        var greeting = 'Welcome';
        if (role === 'PHARMACIST')      greeting = 'Welcome Pharmacist';
        else if (role === 'ADMIN')      greeting = 'Welcome Admin';

        // Set the text; if it changed, restart the fade-in so the new text animates.
        if (textEl) {
            if (textEl.textContent !== greeting) {
                textEl.textContent = greeting;
                textEl.style.animation = 'none';
                // Force reflow so the animation restarts.
                void textEl.offsetWidth;
                textEl.style.animation = '';
            }
        }

        // Show the welcome animation.
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');

        // Give the animation ~1.1s to play, then submit the form so
        // authenticate.php runs and redirects the user.
        window.setTimeout(function () {
            form.submit();
        }, 1100);
    });
})();

</script>

</body>
</html>