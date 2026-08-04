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
<title>Login — Vet Board</title>
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:Arial,sans-serif;background:#f5f4f0;">
<div style="max-width:360px;width:100%;padding:2rem;background:#fff;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.1);">
    <h1 style="font-size:1.1rem;margin-bottom:1rem;">Staff Login</h1>

    <?php if ($error): ?>
        <p style="color:#c0392b;font-size:.85rem;margin-bottom:1rem;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <form method="POST" action="/login.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">

        <div style="margin-bottom:1rem;">
            <label style="display:block;font-size:.85rem;margin-bottom:.3rem;">Email</label>
            <input type="email" name="email" required style="width:100%;padding:.5rem;border:1px solid #ccc;border-radius:4px;">
        </div>

        <div style="margin-bottom:1.25rem;">
            <label style="display:block;font-size:.85rem;margin-bottom:.3rem;">Password</label>
            <input type="password" name="password" required style="width:100%;padding:.5rem;border:1px solid #ccc;border-radius:4px;">
        </div>

        <button type="submit" style="width:100%;padding:.6rem;background:#2a3f54;color:#fff;border:none;border-radius:4px;cursor:pointer;">Log In</button>
    </form>
</div>
</body>
</html>
