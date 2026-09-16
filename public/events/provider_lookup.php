<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Provider;

Auth::startSession();
Auth::requireLogin();

header('Content-Type: application/json');

$accreditationNo = trim($_GET['accreditation_no'] ?? '');

if (empty($accreditationNo)) {
    echo json_encode(['found' => false]);
    exit;
}

$providerModel = new Provider();
$provider      = $providerModel->findByAccreditationNo($accreditationNo);

if (!$provider || !$provider['active']) {
    echo json_encode(['found' => false]);
    exit;
}

echo json_encode([
    'found' => true,
    'id'    => (int) $provider['id'],
    'name'  => $provider['name'],
]);
