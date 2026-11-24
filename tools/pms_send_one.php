<?php
// Sends ONE practitioner's event points to PMS as a test, using the same checks as the PMS Points page.
//   php pms_send_one.php EVENT_ID             -> shows who could be sent (masked), and anything blocking
//   php pms_send_one.php EVENT_ID REG apply   -> sends that one practitioner
// Requires the setting pms_points_send = on. Nothing is sent without 'apply'.
declare(strict_types=1);

$root = getenv('APP_ROOT') ?: '/var/www/html';
require_once $root . '/vendor/autoload.php';
require_once $root . '/config/constants.php';
require_once $root . '/config/database.php';

use VetBoard\Database;
use VetBoard\PmsClient;
use VetBoard\PmsPointsSender;

$eventId = (int) ($argv[1] ?? 0);
$reg     = strtoupper(trim((string) ($argv[2] ?? '')));
$apply   = ($argv[3] ?? '') === 'apply' && $reg !== '';
if ($eventId <= 0) { fwrite(STDERR, "Usage: php pms_send_one.php EVENT_ID [REG apply]\n"); exit(1); }

$pdo = Database::getInstance()->getPdo();
$ev  = $pdo->prepare('SELECT id, title, end_date, event_code, provider_name FROM events WHERE id = :e');
$ev->execute([':e' => $eventId]);
$event = $ev->fetch();
if (!$event) { fwrite(STDERR, "Event not found.\n"); exit(1); }

$mask = static fn(string $s): string => strlen($s) > 4 ? substr($s, 0, 2) . '****' . substr($s, -2) : '****';
$code = trim((string) $event['event_code']);
$blockers = [];

$sender = new PmsPointsSender();
if (!$sender->enabled()) { $blockers[] = 'Setting pms_points_send is off.'; }
if (!preg_match('#^REG/CPD-A/\d{4}/\d{4}$#', $code)) { $blockers[] = 'Event has no valid activity number.'; }
if (date('Y-m-d') < (string) $event['end_date']) { $blockers[] = 'Event has not ended (' . $event['end_date'] . ').'; }

// Activity must be known to PMS (same lookup as the page).
$act = $pdo->prepare('SELECT title FROM pms_activities WHERE upper(trim(activity_number)) = upper(:c) AND removed_at IS NULL LIMIT 1');
$act->execute([':c' => $code]);
$actTitle = $act->fetchColumn();
if ($actTitle === false) {
    $r = (new PmsClient(null, null, 6, 3))->activity($code);
    if ($r['status'] !== PmsClient::FOUND) { $blockers[] = 'PMS does not confirm the activity number.'; }
    else { $actTitle = (string) ($r['data']['title'] ?? $event['title']); }
}

$st = $pdo->prepare(
    'SELECT pl.reg_no, p.name, p.id_number, SUM(pl.points) AS points
     FROM points_ledger pl JOIN participants p ON p.reg_no = pl.reg_no
     WHERE pl.event_id = :e GROUP BY pl.reg_no, p.name, p.id_number HAVING SUM(pl.points) > 0 ORDER BY pl.reg_no'
);
$st->execute([':e' => $eventId]);
$people = $st->fetchAll();
$regs   = array_column($people, 'reg_no');

// Answers saved before the ID-conflict guard may be stale; forget them and ask the register again.
if ($regs) {
    $in = implode(',', array_fill(0, count($regs), '?'));
    $pdo->prepare("DELETE FROM pms_resolutions WHERE reg_no IN ($in)")->execute($regs);
}
for ($i = 0; $i < 200 && $sender->resolve($regs, 20) > 0; $i++) { sleep(1); }
$res  = $sender->resolutions($regs);
$done = $sender->sentFor($eventId);

$ready = [];
foreach ($people as $p) {
    $x = $res[$p['reg_no']] ?? null;
    if ($x && $x['status'] === 'found' && !isset($done[$p['reg_no']])) { $ready[$p['reg_no']] = [$p, $x]; }
}

echo 'Event ' . $eventId . ' | ended ' . $event['end_date'] . ' | people with points ' . count($people) . ' | clean and ready ' . count($ready) . " | already sent " . count($done) . "\n";
foreach ($blockers as $b) { echo "BLOCKED: $b\n"; }

if (!$apply) {
    foreach (array_slice($ready, 0, 15, true) as $rg => [$p, $x]) {
        echo sprintf("  %-10s id %-10s points %5.1f  sent-as %s\n", $rg, $mask((string) $p['id_number']), (float) $p['points'], $x['pms_reg_no']);
    }
    echo "Pick one and run: php pms_send_one.php $eventId REG apply\n";
    exit($blockers ? 2 : 0);
}

if ($blockers) { fwrite(STDERR, "Not sending: blocked.\n"); exit(2); }
if (!isset($ready[$reg])) { fwrite(STDERR, "$reg is not clean and ready for this event; nothing sent.\n"); exit(3); }

[$p, $x] = $ready[$reg];
$h = $pdo->prepare(
    "SELECT ROUND((SUM(EXTRACT(EPOCH FROM (es.end_time - es.start_time))) / 3600)::numeric, 1)
     FROM points_ledger pl JOIN attendance a ON a.id = pl.attendance_id JOIN event_sessions es ON es.id = a.session_id
     WHERE pl.event_id = :e AND pl.reg_no = :r AND pl.kind = 'attendance' AND pl.points > 0"
);
$h->execute([':e' => $eventId, ':r' => $reg]);
$hours = (float) $h->fetchColumn();

$out = $sender->send($eventId, $code, (string) $event['end_date'], (string) ($actTitle ?: $event['title']), trim((string) $event['provider_name']),
    [['reg_no' => $reg, 'pms_reg_no' => (string) $x['pms_reg_no'], 'points' => (float) $p['points'], 'hours' => $hours]], null);
echo 'Sent ' . $out['sent'] . ', rejected ' . $out['failed'] . ', unclear ' . $out['uncertain'] . ($out['message'] !== '' ? ' | PMS said: ' . $out['message'] : '') . "\n";
