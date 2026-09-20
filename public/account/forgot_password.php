<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Mailer;
use VetBoard\Staff;

Auth::startSession();

$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    $email = trim($_POST['email'] ?? '');

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $staffModel = new Staff();
        $rawToken   = $staffModel->createPasswordResetToken($email);

        if ($rawToken !== null) {
            $appUrl   = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8000', '/');
            $resetUrl = $appUrl . '/account/reset_password.php?token=' . urlencode($rawToken);

            Mailer::sendPasswordReset($email, '', $resetUrl);
        }
    }

    // Always show the same message, whether or not the email exists — prevents account enumeration.
    $success = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="public-page">
    <div class="public-shell">
        <div class="public-header">
            <h1>Forgot Password</h1>
            <p>Enter your account email and we'll send a reset link.</p>
        </div>

        <div class="card">
            <?php if ($success): ?>
                <div class="alert alert-success">If that email matches an account, a reset link has been sent. Check your inbox.</div>
                <a href="/login.php" class="btn btn-ghost btn-block">Back to Login</a>
            <?php else: ?>
                <form method="POST" action="/account/forgot_password.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Send Reset Link</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
