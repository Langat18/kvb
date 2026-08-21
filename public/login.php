<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';

use VetBoard\Auth;
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

    $staffModel = new Staff();
    $staff      = $staffModel->verifyPassword($email, $password);

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
<title>System Login — Kenya Veterinary Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { display: flex; align-items: center; justify-content: center; padding: 1rem; }
</style>
</head>
<body>
<div class="auth-shell">
    <div class="auth-hero">
        <div>
            <div class="auth-hero-brand">
                <img src="/assets/img/kvb_logo.png" alt="KVB">
                <span>KENYA VETERINARY BOARD<br><span style="font-weight:400;opacity:.8;">Professional Regulatory Portal</span></span>
            </div>
            <h1>KVB Online Services</h1>
            <p>Secure digital gateway for veterinary professionals in Kenya. Access your licensed services and maintain compliance in one place.</p>
        </div>
        <div class="auth-hero-foot">&copy; Kenya Veterinary Board — Internal Staff Access</div>
    </div>

    <div class="auth-form-pane">
        <div class="auth-date-badge">&#128197; <?= date('l, d F Y') ?></div>

        <h2>System Login</h2>
        <p class="sub">Enter your credentials to access your professional dashboard.</p>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="POST" action="/login.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-group" style="margin-bottom:1.1rem;">
                <div class="field-label-icon"><span class="lbl-icon">&#128100;</span> Username or Email Address</div>
                <div class="input-icon-wrap">
                    <span class="field-icon">&#128100;</span>
                    <input type="email" id="email" name="email" required autocomplete="email" placeholder="Enter your email address">
                </div>
            </div>

            <div class="form-group">
                <div class="field-label-icon"><span class="lbl-icon">&#128273;</span> Password</div>
                <div class="input-icon-wrap">
                    <span class="field-icon">&#128274;</span>
                    <input type="password" id="password" name="password" required style="padding-right:2.5rem;" placeholder="Enter your secure password">
                    <button type="button" id="toggle-pw" style="position:absolute;right:.9rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:.9rem;color:var(--text-muted);padding:0;">&#128065;</button>
                </div>
            </div>

            <div class="auth-row-between">
                <label><input type="checkbox" checked style="accent-color:var(--kvb-green-dark);"> Remember Me</label>
                <a href="mailto:info@kenyavetboard.or.ke">&#10067; Forgot password?</a>
            </div>

            <button type="submit" class="btn btn-primary btn-pill">Sign in to portal &#8594;</button>
        </form>

        <a href="#" class="btn btn-secondary btn-pill" style="margin-top:.75rem;text-decoration:none;display:block;text-align:center;">&#128100;&#43; Create new account</a>

        <div class="auth-note">
            &#127911; Need help logging in? Contact KVB support through: +254-722 305 253, +254-701 581 718, info@kenyavetboard.or.ke
        </div>
    </div>
</div>

<script>
document.getElementById('toggle-pw').addEventListener('click', function() {
    const input = document.getElementById('password');
    input.type = input.type === 'password' ? 'text' : 'password';
});
</script>
</body>
</html>
