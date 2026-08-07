<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Participant;

header('Content-Type: application/json');

$regNo = strtoupper(trim($_GET['reg_no'] ?? ''));

if (empty($regNo) || !Participant::isValidRegNo($regNo)) {
    echo json_encode(['found' => false]);
    exit;
}

$participantModel = new Participant();
$participant       = $participantModel->findByRegNo($regNo);

if (!$participant) {
    echo json_encode(['found' => false]);
    exit;
}

echo json_encode([
    'found'      => true,
    'name'       => $participant['name'],
    'id_number'  => $participant['id_number'],
    'email'      => $participant['email'],
    'phone'      => $participant['phone'] ?? '',
]);
