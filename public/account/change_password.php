<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Staff;

Auth::startSession();
Auth::requireLogin();

$breadcrumbs = [['label' => 'Dashboard', 'url' => '/index.php'], ['label' => 'Change Password', 'url' => null]];

$user  = Auth::user();
$error = $_SESSION['error']   ?? null;
$success = $_SESSION['success'] ?? null;
unset($_SESSION['error'], $_SESSION['success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    $currentPassword = trim($_POST['current_password'] ?? '');
    $newPassword     = trim($_POST['new_password']     ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');

    $staffModel = new Staff();

    $verified = $staffModel->verifyPassword($user['email'], $currentPassword);
    if (!$verified) {
        $_SESSION['error'] = 'Current password is incorrect.';
        header('Location: /account/change_password.php');
        exit;
    }

    if ($newPassword !== $confirmPassword) {
        $_SESSION['error'] = 'New password and confirmation do not match.';
        header('Location: /account/change_password.php');
        exit;
    }

    try {
        $staffModel->resetPassword((int) $user['id'], $newPassword);
        $_SESSION['success'] = 'Password changed successfully.';
    } catch (\InvalidArgumentException $e) {
        $_SESSION['error'] = $e->getMessage();
    }

    header('Location: /account/change_password.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Change Password</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { display: block; padding: 2.5rem; }
.container { max-width: 420px; margin: 0 auto; }
.container h1 { font-size: 1.2rem; font-weight: 700; color: var(--kvb-green-dark); margin-bottom: 1rem; }
.form-group { margin-bottom: 1rem; }
.pw-wrap { position: relative; }
.pw-wrap input { padding-right: 2.5rem; }
.pw-toggle { position: absolute; right: .6rem; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; font-size: .85rem; color: var(--text-muted); padding: 0; }
</style>
</head>
<body>
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="container">
    <h1>Change Password</h1>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="POST" action="/account/change_password.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-group">
                <label for="current_password">Current Password</label>
                <div class="pw-wrap">
                    <input type="password" id="current_password" name="current_password" required>
                    <button type="button" class="pw-toggle" data-target="current_password">&#128065;</button>
                </div>
            </div>

            <div class="form-group">
                <label for="new_password">New Password</label>
                <div class="pw-wrap">
                    <input type="password" id="new_password" name="new_password" required minlength="8">
                    <button type="button" class="pw-toggle" data-target="new_password">&#128065;</button>
                </div>
                <small style="color:var(--text-muted);">At least 8 characters.</small>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm New Password</label>
                <div class="pw-wrap">
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
                    <button type="button" class="pw-toggle" data-target="confirm_password">&#128065;</button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;">Update Password</button>
        </form>
    </div>

    <div style="margin-top:1rem;">
    </div>
</div>

<script>
document.querySelectorAll('.pw-toggle').forEach(btn => {
    btn.addEventListener('click', function() {
        const input = document.getElementById(this.dataset.target);
        input.type = input.type === 'password' ? 'text' : 'password';
    });
});
</script>
</body>
</html>
