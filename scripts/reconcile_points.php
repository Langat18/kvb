<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';

use VetBoard\Database;
use VetBoard\Registration;

// Usage: php scripts/reconcile_points.php <event_id>
// Re-attempts points-awarding for every non-virtual, non-flagged attendance
// row on the given event. Idempotent — already-awarded rows are skipped
// automatically by maybeAwardPoints(), so this is safe to run repeatedly
// or against an entire event without risk of double-awarding.

$eventId = isset($argv[1]) ? (int) $argv[1] : 0;

if ($eventId <= 0) {
    fwrite(STDERR, "Usage: php scripts/reconcile_points.php <event_id>\n");
    exit(1);
}

$pdo = Database::getInstance()->getPdo();

$stmt = $pdo->prepare(
    'SELECT a.id, a.registration_id, a.session
     FROM attendance a
     JOIN registrations r ON r.id = a.registration_id
     WHERE r.event_id = :event_id AND a.method != :virtual
     ORDER BY a.id ASC'
);
$stmt->execute([':event_id' => $eventId, ':virtual' => 'virtual']);
$rows = $stmt->fetchAll();

if (empty($rows)) {
    echo "No non-virtual attendance rows found for event {$eventId}.\n";
    exit(0);
}

$registrationModel = new Registration();
$checked = 0;
$before  = [];

foreach ($rows as $row) {
    $beforeStmt = $pdo->prepare('SELECT EXISTS(SELECT 1 FROM points_ledger WHERE attendance_id = :id)');
    $beforeStmt->execute([':id' => $row['id']]);
    $before[$row['id']] = (bool) $beforeStmt->fetchColumn();
}

foreach ($rows as $row) {
    try {
        $registrationModel->maybeAwardPoints((int) $row['registration_id'], $row['session']);
    } catch (\Throwable $e) {
        echo "  attendance {$row['id']}: ERROR — " . $e->getMessage() . "\n";
        continue;
    }
    $checked++;
}

$newlyAwarded = 0;

foreach ($rows as $row) {
    if ($before[$row['id']]) {
        continue;
    }

    $afterStmt = $pdo->prepare('SELECT points, reason FROM points_ledger WHERE attendance_id = :id');
    $afterStmt->execute([':id' => $row['id']]);
    $ledgerRow = $afterStmt->fetch();

    if ($ledgerRow) {
        $newlyAwarded++;
        echo "  attendance {$row['id']} (session {$row['session']}): awarded {$ledgerRow['points']} pts — {$ledgerRow['reason']}\n";
    }
}

echo "\nChecked {$checked}/" . count($rows) . " attendance rows. Newly awarded: {$newlyAwarded}.\n";
