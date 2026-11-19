<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\PmsAutofill;

Auth::startSession();

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Auth::validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['status' => 'invalid']);
    exit;
}

$autofill = new PmsAutofill();

if ($autofill->idMode() === null) {
    echo json_encode(['status' => 'off']);
    exit;
}

$ip = PmsAutofill::clientIp();

try {
    $result = $autofill->verifyId((string) ($_POST['reg_no'] ?? ''), (string) ($_POST['id_part'] ?? ''), $ip);
} catch (\Throwable $e) {
    error_log('PMS autofill failed: ' . $e->getMessage());
    $result = ['status' => 'unavailable'];
}

// The verified record stays on the server for this visit, so the full ID is never typed or sent again.
unset($_SESSION['reg_verified']);
if ($result['status'] === 'ok') {
    $_SESSION['reg_verified'] = ['reg_no' => $result['reg_no'], 'id' => $result['id'], 'source' => $result['source'], 'at' => time()];
}
unset($result['id']);

echo json_encode($result);
