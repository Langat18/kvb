<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Staff;

Auth::startSession();

$token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));

if ($token === '') {
    die('Invalid or missing reset token.');
}

$staffModel = new Staff();
$tokenRow   = $staffModel->findByResetToken($token);

if (!$tokenRow) {
    die('This reset link is invalid or has expired. Please request a new one from the <a href="/account/forgot_password.php">forgot password page</a>.');
}

$error   = null;
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    $newPassword     = trim($_POST['new_password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');

    if ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $staffModel->resetPasswordWithToken((int) $tokenRow['token_id'], (int) $tokenRow['id'], $newPassword);
            $success = true;
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="referrer" content="no-referrer">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="public-page">
    <div class="public-shell">
        <div class="public-header">
            <h1>Reset Password</h1>
            <p>For <?= htmlspecialchars($tokenRow['email'], ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <div class="card">
            <?php if ($success): ?>
                <div class="alert alert-success">Password reset successfully.</div>
                <a href="/login.php" class="btn btn-primary btn-block">Log In</a>
            <?php else: ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <form method="POST" action="/account/reset_password.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" required minlength="8">
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Reset Password</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
