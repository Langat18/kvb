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
<title>Login Kenya Veterinary Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { display: flex; align-items: center; justify-content: center; }
.login-card { max-width: 420px; width: 100%; text-align: center; padding: 2.5rem; }
.login-card img.logo { max-width: 280px; width: 100%; height: auto; margin-bottom: 1.5rem; }
.login-card h1 { font-size: 1.35rem; font-weight: 700; color: var(--kvb-green-dark); margin-bottom: .2rem; }
.login-card p.sub { font-size: .9rem; color: var(--text-muted); margin-bottom: 2rem; }
.form-group { margin-bottom: 1.1rem; text-align: left; }
.footer-note { font-size: .8rem; color: var(--text-muted); margin-top: 1.5rem; }
</style>
</head>
<body>
<div class="card login-card">
    <img src="/assets/img/kvb_logo.png" alt="Kenya Veterinary Board" class="logo">
    <h1>Kenya Veterinary Board</h1>
    <p class="sub">Event Attendance System</p>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="POST" action="/login.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">

        <div class="form-group">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" required>
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%;">Sign In</button>
    </form>

    <p class="footer-note">Forgot your password? Contact your system administrator.</p>
</div>
</body>
</html>
