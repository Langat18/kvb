<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$result = (new VetBoard\PmsDirectory())->sync($argv[1] ?? 'cron');

if (!$result['ok']) {
    fwrite(STDERR, date('c') . ' PMS sync failed: ' . $result['message'] . PHP_EOL);
    exit(1);
}

echo date('c') . ' PMS sync ok: ' . $result['providers_seen'] . ' providers, ' . $result['activities_seen'] . ' activities ('
    . $result['activities_added'] . ' new, ' . $result['activities_changed'] . ' changed), '
    . $result['events_linked'] . ' events linked' . PHP_EOL;
