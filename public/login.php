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
<title>Login — Kenya Veterinary Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { display: flex; align-items: center; justify-content: center; }
.login-card { max-width: 380px; width: 100%; }
.login-card h1 { font-size: 1.15rem; font-weight: 700; margin-bottom: .3rem; color: var(--kvb-green-dark); }
.login-card p.sub { font-size: .85rem; color: var(--text-muted); margin-bottom: 1.5rem; }
.form-group { margin-bottom: 1rem; }
</style>
</head>
<body>
<div class="card login-card">
    <h1>Staff Login</h1>
    <p class="sub">Kenya Veterinary Board — Event Attendance System</p>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="POST" action="/login.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">

        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" required>
        </div>

        <div class="form-group" style="margin-bottom:1.5rem;">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%;">Log In</button>
    </form>
</div>
</body>
</html>
