<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Participant;
use VetBoard\PmsAutofill;

header('Content-Type: application/json');
header('Cache-Control: no-store');

function maskWord(string $w): string
{
    $len = mb_strlen($w);

    return $len <= 1 ? $w : mb_substr($w, 0, 1) . str_repeat('*', min($len - 1, 6));
}

function maskName(string $name): string
{
    return implode(' ', array_map('maskWord', preg_split('/\s+/', trim($name)) ?: []));
}

function maskEmail(string $email): string
{
    $at = strrpos($email, '@');

    return $at === false || $at === 0 ? '' : mb_substr($email, 0, 1) . '***' . substr($email, $at);
}

function maskPhone(string $phone): string
{
    $len = strlen($phone);

    if ($len < 6) {
        return $len > 0 ? substr($phone, 0, 1) . str_repeat('*', $len - 1) : '';
    }

    return substr($phone, 0, 2) . str_repeat('*', $len - 5) . substr($phone, -3);
}

$regNo = strtoupper(trim($_GET['reg_no'] ?? ''));

if ($regNo === '' || !Participant::isValidRegNo($regNo)) {
    echo json_encode(['status' => 'invalid']);
    exit;
}

// Step one: is this number known, and how is it spelled? Nothing about the person is revealed here.
$autofill = new PmsAutofill();

try {
    $r = $autofill->resolve($regNo, PmsAutofill::clientIp());
} catch (\Throwable $e) {
    error_log('Registration lookup failed: ' . $e->getMessage());
    $r = ['status' => 'unavailable'];
}

// The 4-character check is offered whenever it is switched on; otherwise the full ID is asked for.
$verify = $autofill->idMode() !== null;

if ($r['status'] === 'local') {
    $p = $r['participant'];
    echo json_encode([
        'status' => 'local',
        'reg_no' => $r['reg_no'],
        'verify' => $verify,
        'name'   => maskName((string) $p['name']),
        'email'  => maskEmail((string) $p['email']),
        'phone'  => maskPhone((string) ($p['phone'] ?? '')),
    ]);
    exit;
}

echo json_encode(['status' => $r['status'], 'reg_no' => $r['reg_no'] ?? $regNo, 'verify' => $verify && $r['status'] === 'pms']);
