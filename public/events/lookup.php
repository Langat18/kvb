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

if (empty($regNo) || !Participant::isValidRegNo($regNo)) {
    echo json_encode(['found' => false]);
    exit;
}

$participant = (new Participant())->findByRegNo($regNo);

if (!$participant) {
    echo json_encode(['found' => false, 'pms' => Participant::isValidNewRegNo($regNo) && (new PmsAutofill())->enabled()]);
    exit;
}

echo json_encode([
    'found'  => true,
    'masked' => true,
    'name'   => maskName((string) $participant['name']),
    'email'  => maskEmail((string) $participant['email']),
    'phone'  => maskPhone((string) ($participant['phone'] ?? '')),
]);
