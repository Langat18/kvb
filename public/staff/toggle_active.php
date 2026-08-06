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

$id     = (int) ($_POST['id']     ?? 0);
$active = ($_POST['active'] ?? '') === '1';

$user = Auth::user();
if ($id === (int) $user['id']) {
    $_SESSION['error'] = 'You cannot deactivate your own account.';
    header('Location: /staff/index.php');
    exit;
}

$staffModel = new Staff();
$staffModel->setActive($id, $active);
$_SESSION['success'] = $active ? 'Account reactivated.' : 'Account deactivated.';

header('Location: /staff/index.php');
exit;
