<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Staff;

Auth::startSession();
Auth::requirePermission('manage_staff');

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

$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/index.php'],
    ['label' => 'Staff', 'url' => '/staff/index.php'],
    ['label' => 'Reset Password', 'url' => null],
];

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

require __DIR__ . '/../templates/sidebar_data.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="page-with-sidebar">
    <div class="app-page-hero">
        <div>
            <h1>Reset Password</h1>
            <div class="sub">For <?= htmlspecialchars($target['name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($target['email'], ENT_QUOTES, 'UTF-8') ?>)</div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="app-card">
        <form method="POST" action="/staff/reset_password.php?id=<?= $targetId ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-group">
                <label for="new_password">New Temporary Password</label>
                <input type="text" id="new_password" name="new_password" required minlength="8">
                <small style="color:var(--text-muted);">Share this with them directly; they should change it after logging in.</small>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Reset Password</button>
        </form>
    </div>

    <div style="margin-top:1rem;">
        <a href="/staff/index.php" class="btn btn-secondary">&larr; Back to Staff List</a>
    </div>
</div>
</body>
</html>
