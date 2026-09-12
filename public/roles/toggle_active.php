<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Role;

Auth::startSession();
Auth::requirePermission('manage_roles');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    die('Invalid security token.');
}

$id     = (int) ($_POST['id'] ?? 0);
$active = ($_POST['active'] ?? '0') === '1';

$roleModel = new Role();

try {
    $roleModel->setActive($id, $active);
    $_SESSION['success'] = $active ? 'Role reactivated.' : 'Role deactivated.';
} catch (\InvalidArgumentException $e) {
    $_SESSION['error'] = $e->getMessage();
}

header('Location: /roles/index.php');
exit;
