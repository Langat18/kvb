<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\CadreRepository;
use VetBoard\EventModeRepository;
use VetBoard\SessionWindowRepository;

const LOOKUP_TYPES = [
    'event-modes' => ['repository' => EventModeRepository::class],
    'cadres'      => ['repository' => CadreRepository::class],
    'sessions'    => ['repository' => SessionWindowRepository::class],
];

Auth::startSession();
Auth::requirePermission('manage_lookups');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    die('Invalid security token.');
}

$type = $_POST['type'] ?? '';

if (!array_key_exists($type, LOOKUP_TYPES)) {
    http_response_code(404);
    die('Unknown lookup type.');
}

$repoClass = LOOKUP_TYPES[$type]['repository'];
$repo      = new $repoClass();

$id     = (int) ($_POST['id'] ?? 0);
$active = ($_POST['active'] ?? '0') === '1';

$repo->setActive($id, $active);

$_SESSION['success'] = $active ? 'Item reactivated.' : 'Item deactivated.';
header('Location: /lookups/index.php?type=' . urlencode($type));
exit;