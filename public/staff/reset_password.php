<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Staff;

Auth::startSession();
Auth::requireRole('admin');

$user       = Auth::user();
$staffModel = new Staff();

if (empty($_GET['id'])) {
    header('Location: /staff/index.php');
    exit;
}

$targetId = (int) $_GET['id'];
$target   = $staffModel->findById($targetId);

if (!$target) {
    $_SESSION['error'] = 'Staff member not found.';
    header('Location: /staff/index.php');
    exit;
}

$error   = $_SESSION['error']   ?? null;
$success = $_SESSION['success'] ?? null;
unset($_SESSION['error'], $_SESSION['success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    $newPassword = trim($_POST['new_password'] ?? '');

    try {
        $staffModel->resetPassword($targetId, $newPassword);
        $_SESSION['success'] = 'Password reset for ' . $target['name'] . '. Share the new password with them directly.';
        header('Location: /staff/index.php');
        exit;
    } catch (\InvalidArgumentException $e) {
        $_SESSION['error'] = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password — Kenya Veterinary Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { display: block; padding: 2.5rem; }
.container { max-width: 420px; margin: 0 auto; }
.container h1 { font-size: 1.15rem; font-weight: 700; color: var(--kvb-green-dark); margin-bottom: .3rem; }
.container p.sub { font-size: .85rem; color: var(--text-muted); margin-bottom: 1rem; }
.form-group { margin-bottom: 1rem; }
</style>
</head>
<body>
<div class="container">
    <h1>Reset Password</h1>
    <p class="sub">For <?= htmlspecialchars($target['name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($target['email'], ENT_QUOTES, 'UTF-8') ?>)</p>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="POST" action="/staff/reset_password.php?id=<?= $targetId ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-group">
                <label for="new_password">New Temporary Password</label>
                <input type="text" id="new_password" name="new_password" required minlength="8">
                <small style="color:var(--text-muted);">Share this with them directly; they should change it after logging in.</small>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;">Reset Password</button>
        </form>
    </div>

    <div style="margin-top:1rem;">
        <a href="/staff/index.php" class="btn btn-secondary">&larr; Back to Staff List</a>
    </div>
</div>
</body>
</html>
