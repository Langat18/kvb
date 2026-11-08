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

if (!$autofill->enabled()) {
    echo json_encode(['status' => 'off']);
    exit;
}

$forwarded = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
$ip        = filter_var($forwarded, FILTER_VALIDATE_IP) ? $forwarded : (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

try {
    $result = $autofill->verify((string) ($_POST['reg_no'] ?? ''), (string) ($_POST['dob'] ?? ''), $ip);
} catch (\Throwable $e) {
    error_log('PMS autofill failed: ' . $e->getMessage());
    $result = ['status' => 'unavailable'];
}

echo json_encode($result);
