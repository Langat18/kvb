<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Staff;

Auth::startSession();
Auth::requirePermission('manage_staff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    die('Invalid security token.');
}

$id     = (int) ($_POST['id'] ?? 0);
$roleId = (int) ($_POST['role_id'] ?? 0);

$staffModel = new Staff();

try {
    $staffModel->updateRole($id, $roleId);
    $_SESSION['success'] = 'Role updated successfully.';
} catch (\InvalidArgumentException $e) {
    $_SESSION['error'] = $e->getMessage();
}

header('Location: /staff/index.php');
exit;
