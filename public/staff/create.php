<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Role;
use VetBoard\Staff;

Auth::startSession();
Auth::requirePermission('manage_staff');

$user      = Auth::user();
$roleModel = new Role();
$roles     = $roleModel->list(true);

$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/index.php'],
    ['label' => 'Staff', 'url' => '/staff/index.php'],
    ['label' => 'New Staff', 'url' => null],
];

$error    = $_SESSION['error']     ?? null;
$formData = $_SESSION['form_data'] ?? [];
unset($_SESSION['error'], $_SESSION['form_data']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    $data = [
        'name'     => trim($_POST['name']     ?? ''),
        'email'    => trim($_POST['email']    ?? ''),
        'password' => trim($_POST['password'] ?? ''),
        'role_id'  => $_POST['role_id'] !== '' ? (int) $_POST['role_id'] : null,
    ];

    try {
        $staffModel = new Staff();
        $staffModel->create($data);
        $_SESSION['success'] = 'Staff account created successfully.';
        header('Location: /staff/index.php');
        exit;

    } catch (\InvalidArgumentException $e) {
        $_SESSION['error']     = $e->getMessage();
        $_SESSION['form_data'] = $data;
        header('Location: /staff/create.php');
        exit;
    }
}

require __DIR__ . '/../templates/sidebar_data.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>New Staff</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="page-with-sidebar">
    <div class="app-page-hero">
        <div><h1>New Staff Account</h1></div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="app-card">
        <form method="POST" action="/staff/create.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-group">
                <label for="name">Full Name <span class="required">*</span></label>
                <input type="text" id="name" name="name" required value="<?= htmlspecialchars($formData['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="form-group">
                <label for="email">Email <span class="required">*</span></label>
                <input type="email" id="email" name="email" required value="<?= htmlspecialchars($formData['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="form-group">
                <label for="password">Temporary Password <span class="required">*</span></label>
                <input type="text" id="password" name="password" required minlength="8">
                <small style="color:var(--text-muted);">Share this with the staff member; they should change it after first login.</small>
            </div>

            <div class="form-group">
                <label for="role_id">Role <span class="required">*</span></label>
                <select id="role_id" name="role_id" required>
                    <option value="">-- Select --</option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= (int) $role['id'] ?>" <?= (int) ($formData['role_id'] ?? 0) === (int) $role['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($role['label'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Create Account</button>
        </form>
    </div>

    <div style="margin-top:1rem;">
        <a href="/staff/index.php" class="btn btn-secondary">&larr; Back to Staff List</a>
    </div>
</div>
</body>
</html>
