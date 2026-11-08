<?php

declare(strict_types=1);

require_once '/var/www/html/vendor/autoload.php';
require_once '/var/www/html/config/constants.php';
require_once '/var/www/html/config/database.php';

$pdo   = VetBoard\Database::getInstance()->getPdo();
$bonus = new VetBoard\PresenterBonus($pdo);
$dry   = getenv('DRY') === '1';

$eventIds = $pdo->query(
    "SELECT DISTINCT s.event_id
     FROM session_presentations sp
     JOIN event_sessions s ON s.id = sp.session_id
     WHERE sp.reg_no IS NOT NULL
     UNION
     SELECT DISTINCT event_id FROM points_ledger WHERE kind = 'presenter_bonus'
     ORDER BY 1"
)->fetchAll(PDO::FETCH_COLUMN);

$total = ['added' => 0, 'updated' => 0, 'removed' => 0];

$pdo->beginTransaction();

try {
    foreach ($eventIds as $id) {
        $changes = $bonus->reconcile((int) $id);

        foreach ($changes as $kind => $n) {
            $total[$kind] += $n;
        }

        if (array_sum($changes) > 0) {
            printf("event %d: +%d ~%d -%d\n", $id, $changes['added'], $changes['updated'], $changes['removed']);
        }
    }

    $dry ? $pdo->rollBack() : $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, 'Failed, nothing changed: ' . $e->getMessage() . "\n");
    exit(1);
}

printf(
    "%s: %d events checked, added %d, updated %d, removed %d\n",
    $dry ? 'DRY RUN (rolled back)' : 'APPLIED',
    count($eventIds),
    $total['added'],
    $total['updated'],
    $total['removed']
);
