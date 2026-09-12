<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Department;

Auth::startSession();
Auth::requirePermission('manage_departments');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    die('Invalid security token.');
}

$id     = (int) ($_POST['id'] ?? 0);
$active = ($_POST['active'] ?? '0') === '1';

$deptModel = new Department();
$deptModel->setActive($id, $active);

$_SESSION['success'] = $active ? 'Department reactivated.' : 'Department deactivated.';
header('Location: /departments/index.php');
exit;