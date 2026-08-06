<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Staff;

Auth::startSession();
Auth::requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    die('Invalid request.');
}

$id   = (int) ($_POST['id']   ?? 0);
$role = $_POST['role'] ?? '';

$user = Auth::user();
if ($id === (int) $user['id']) {
    $_SESSION['error'] = 'You cannot change your own role.';
    header('Location: /staff/index.php');
    exit;
}

try {
    $staffModel = new Staff();
    $staffModel->updateRole($id, $role);
    $_SESSION['success'] = 'Role updated successfully.';
} catch (\InvalidArgumentException $e) {
    $_SESSION['error'] = $e->getMessage();
}

header('Location: /staff/index.php');
exit;
