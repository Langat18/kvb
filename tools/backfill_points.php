<?php
// Pays points for sign-ins that qualify but were never paid, using the system's own rules
// (denied and pending-review sign-ins stay unpaid; quizzes, session points and the daily cap apply).
//   php backfill_points.php EVENT_ID          -> dry run: shows what would be paid, changes nothing
//   php backfill_points.php EVENT_ID apply    -> pays them
declare(strict_types=1);

$root = getenv('APP_ROOT') ?: '/var/www/html';
require_once $root . '/vendor/autoload.php';
require_once $root . '/config/constants.php';
require_once $root . '/config/database.php';

$eventId = (int) ($argv[1] ?? 0);
$apply   = ($argv[2] ?? '') === 'apply';
if ($eventId <= 0) { fwrite(STDERR, "Usage: php backfill_points.php EVENT_ID [apply]\n"); exit(1); }

$pdo = VetBoard\Database::getInstance()->getPdo();
$reg = new VetBoard\Registration();

$ids = $pdo->prepare(
    'SELECT a.id FROM attendance a JOIN registrations r ON r.id = a.registration_id
     WHERE r.event_id = :e AND a.session_id IS NOT NULL AND NOT a.points_denied AND NOT a.flagged
       AND NOT EXISTS (SELECT 1 FROM points_ledger pl WHERE pl.attendance_id = a.id)
     ORDER BY a.attendance_date, a.id'
);
$ids->execute([':e' => $eventId]);
$list = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));

$sum = $pdo->prepare('SELECT COALESCE(SUM(points),0) FROM points_ledger WHERE event_id = :e');
$sum->execute([':e' => $eventId]); $before = (float) $sum->fetchColumn();

$pdo->beginTransaction();
$paid = 0;
foreach ($list as $id) {
    $reg->maybeAwardPointsForAttendance($id);
    $c = $pdo->prepare('SELECT 1 FROM points_ledger WHERE attendance_id = :a'); $c->execute([':a' => $id]);
    if ($c->fetchColumn()) { $paid++; }
}
$sum->execute([':e' => $eventId]); $after = (float) $sum->fetchColumn();

printf("Event %d: %d qualifying sign-ins without points; %d would be paid; %s points added.\n", $eventId, count($list), $paid, number_format($after - $before, 1));
printf("Not paid by the rules (quiz not passed, daily cap, or session pays 0): %d\n", count($list) - $paid);

if ($apply) { $pdo->commit(); echo "APPLIED.\n"; } else { $pdo->rollBack(); echo "Dry run only. Nothing was changed. Add the word apply to pay them.\n"; }
