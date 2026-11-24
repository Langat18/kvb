<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Role;

Auth::startSession();
Auth::requirePermission('manage_roles');

$error    = $_SESSION['error']     ?? null;
$formData = $_SESSION['form_data'] ?? [];
unset($_SESSION['error'], $_SESSION['form_data']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    $data = [
        'item_key' => trim($_POST['item_key'] ?? ''),
        'label'    => trim($_POST['label'] ?? ''),
    ];

    try {
        $roleModel = new Role();
        $roleId    = $roleModel->create($data);
        $_SESSION['success'] = 'Role created successfully. Set its permissions next.';
        header('Location: /roles/edit.php?id=' . $roleId);
        exit;

    } catch (\InvalidArgumentException $e) {
        $_SESSION['error']     = $e->getMessage();
        $_SESSION['form_data'] = $data;
        header('Location: /roles/create.php');
        exit;
    }
}
$user = Auth::user();
$breadcrumbs = [['label' => 'Dashboard', 'url' => '/index.php'], ['label' => 'Roles', 'url' => '/roles/index.php'], ['label' => 'New Role', 'url' => null]];
require __DIR__ . '/../templates/sidebar_data.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>New Role</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=<?= (int) @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/style.css') ?>">
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="page-with-sidebar">
<div class="form-page">
    <div class="app-page-hero"><div><h1>New Role</h1></div></div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="POST" action="/roles/create.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-group">
                <label for="item_key">Key <span class="required">*</span></label>
                <input type="text" id="item_key" name="item_key" required pattern="[a-z0-9_]+"
                       value="<?= htmlspecialchars($formData['item_key'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <small style="color:var(--text-muted);">Lowercase letters, numbers, underscores only. Cannot be changed later.</small>
            </div>

            <div class="form-group">
                <label for="label">Label <span class="required">*</span></label>
                <input type="text" id="label" name="label" required
                       value="<?= htmlspecialchars($formData['label'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <button type="submit" class="btn btn-primary btn-block">Create Role</button>
        </form>
    </div>

    <div style="margin-top:1rem;">
        <a href="/roles/index.php" class="btn btn-secondary">Back to Roles</a>
    </div>
</div>
</div>
</body>
</html>
