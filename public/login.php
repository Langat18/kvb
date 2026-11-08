<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';

use VetBoard\Auth;
use VetBoard\LoginThrottle;
use VetBoard\Staff;

Auth::startSession();

if (!empty($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

$error = $_SESSION['login_error'] ?? null;
unset($_SESSION['login_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    $throttle = new LoginThrottle();

    if ($throttle->isLocked($email)) {
        $minutes = $throttle->minutesUntilUnlock($email);
        $_SESSION['login_error'] = "Too many failed login attempts. Please try again in about {$minutes} minute(s).";
        header('Location: /login.php');
        exit;
    }

    $staffModel = new Staff();
    $staff      = $staffModel->verifyPassword($email, $password);

    $throttle->recordAttempt($email, $staff !== null);

    if (!$staff) {
        $_SESSION['login_error'] = 'Invalid email or password.';
        header('Location: /login.php');
        exit;
    }

    Auth::login($staff);
    header('Location: /index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="/assets/css/style.css?v=<?= (int) @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/style.css') ?>">
<style>
body { display: flex; align-items: center; justify-content: center; padding: 1rem; }
</style>
</head>
<body>
<div class="auth-shell kvb-auth-shell">
    <div class="auth-hero kvb-left">
        <div class="kvb-left-top">
            <div class="kvb-logo-pill">
                <div class="kvb-logo-pill-icon">
                    <img src="/assets/img/kvb_logo.png" alt="KVB Logo">
                </div>
                <div>
                    <div class="kvb-logo-pill-text-main">KENYA VETERINARY BOARD</div>
                    <div class="kvb-logo-pill-text-sub">Professional Regulatory Portal</div>
                </div>
            </div>
            <div>
                <div class="kvb-left-title">KVB Online Services</div>
                <div class="kvb-left-sub">
                    Secure digital gateway for veterinary professionals in Kenya. Access your licensed services and maintain compliance in one place.
                </div>
            </div>
        </div>
        <div class="auth-hero-foot">&copy; Kenya Veterinary Board</div>
    </div>

    <div class="kvb-right">
        <div class="kvb-date-pill">
            <i class="fa fa-calendar-day"></i> <?= date('l, d F Y') ?>
        </div>

        <div class="kvb-right-header">
            <div class="kvb-right-title">System Login</div>
            <div class="kvb-right-sub">Enter your credentials to access your professional dashboard.</div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form id="login-form" class="kvb-form" action="/login.php" method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">

            <div class="kvb-form-body">
                <div class="kvb-field">
                    <div class="kvb-field-label"><i class="fa fa-user"></i> <span>Username or Email Address</span></div>
                    <div class="kvb-input-wrap">
                        <i class="fa fa-user kvb-input-icon"></i>
                        <input type="email" id="email" class="form-control" name="email" placeholder="Enter your email address" autocomplete="email" required>
                    </div>
                </div>

                <div class="kvb-field">
                    <div class="kvb-field-label"><i class="fa fa-key"></i> <span>Password</span></div>
                    <div class="kvb-input-wrap">
                        <i class="fa fa-lock kvb-input-icon"></i>
                        <input type="password" id="password-field" class="form-control" name="password" placeholder="Enter your secure password" autocomplete="current-password" required>
                        <i class="fa fa-eye" id="togglePassword"></i>
                    </div>
                </div>

                <div class="kvb-form-footer-top">
                    <div class="kvb-remember">
                        <div class="form-check">
                            <input type="checkbox" id="rememberMe" class="form-check-input" checked>
                            <label class="form-check-label" for="rememberMe">Remember Me</label>
                        </div>
                    </div>
                    <a href="/account/forgot_password.php"><i class="fa fa-circle-question"></i> Forgot password?</a>
                </div>

                <button type="submit" class="kvb-btn kvb-btn-primary" id="login-btn">
                    <span class="btn-text">Sign in to portal</span>
                    <i class="fa fa-arrow-right-to-bracket"></i>
                    <div class="kvb-loading" aria-hidden="true"></div>
                </button>

                <div class="kvb-support">
                    <i class="fa fa-headset"></i>
                    <span>Need help logging in? Contact KVB support through: +254-722 305 253, +254-701 581 718, info@kenyavetboard.or.ke</span>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const loginForm     = document.getElementById('login-form');
    const loginBtn      = document.getElementById('login-btn');
    const btnText       = loginBtn.querySelector('.btn-text');
    const loadingSpinner = loginBtn.querySelector('.kvb-loading');
    const togglePassword = document.getElementById('togglePassword');
    const passwordField  = document.getElementById('password-field');

    loginForm.addEventListener('submit', function() {
        btnText.textContent = 'Signing in...';
        loadingSpinner.style.display = 'block';
        loginBtn.disabled = true;
    });

    if (togglePassword && passwordField) {
        togglePassword.addEventListener('click', () => {
            const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordField.setAttribute('type', type);
        });
    }
});
</script>
</body>
</html>
