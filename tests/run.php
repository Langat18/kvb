<?php
// Dependency-free checks. Run: php tests/run.php   (exit code 1 on any failure)
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use VetBoard\PmsMessage;
use VetBoard\PmsPointsSender;

ini_set('error_log', '/dev/null');
$fail = 0;
$check = static function (string $name, bool $ok) use (&$fail): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . "\n";
    if (!$ok) { $fail++; }
};

$sql = "SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'record_sn' at row 1 The SQL being executed was: INSERT INTO `cpd_activity_points` (`record_sn`) VALUES ('CPD-1-VTA00617')";
$m = PmsMessage::explain($sql, 200);
$check('SQL error text is not shown', stripos($m, 'SQLSTATE') === false && stripos($m, 'INSERT') === false && stripos($m, 'record_sn') === false && stripos($m, 'cpd_activity_points') === false);
$check('SQL error gets a plain message and a reference', str_contains($m, 'problem on its side') && preg_match('/\(ref PMS-[0-9A-F]{6}\)$/', $m) === 1);
$check('same reply gives the same reference', PmsMessage::explain($sql, 200) === $m);
$check('token refusal is plain', str_contains(PmsMessage::explain('Unauthenticated.', 401), 'current access token'));
$check('timeout is plain', str_contains(PmsMessage::explain('No answer from PMS: Operation timed out after 8000 ms', 0), 'outcome is not known'));
$check('duplicate is plain', str_contains(PmsMessage::explain('Duplicate entry for key', 200), 'already holds'));
$check('unknown text still gets a plain message', str_contains(PmsMessage::explain('weird', 200), 'could not process'));
$check('SQL error counts as failed inside the batch', PmsMessage::failedInsideBatch($sql, 200));
$check('server error counts as failed inside the batch', PmsMessage::failedInsideBatch('', 500));
$check('plain validation refusal is not inside the batch', !PmsMessage::failedInsideBatch('The activity number is required', 422));

$check('serial keeps the usual form when short', PmsPointsSender::recordSn(8, '1163') === 'CPD-8-1163');
$check('serial drops the VT prefix when long', PmsPointsSender::recordSn(1, 'VTA00617') === '1-A00617');
$check('serial is never longer than 10', strlen(PmsPointsSender::recordSn(123, 'VTB00357')) <= 10);
$check('serials differ per event and number', PmsPointsSender::recordSn(1, 'VTA00617') !== PmsPointsSender::recordSn(1, 'VTB00617'));

$check('saved raw SQL text is explained on display', stripos(PmsMessage::forDisplay($sql), 'SQLSTATE') === false);
$check('saved plain text passes through', PmsMessage::forDisplay('Sent (ref PMS-ABC123)') === 'Sent (ref PMS-ABC123)');

// The sender's reading of PMS replies, without a database.
$s  = (new ReflectionClass(PmsPointsSender::class))->newInstanceWithoutConstructor();
$im = new ReflectionMethod($s, 'interpret'); $im->setAccessible(true);
$rows = ['3145' => 1, 'VTA00617' => 2];
[, $failed, $unsure, $note] = $im->invoke($s, ['status' => 'found', 'http' => 200, 'message' => $sql, 'json' => ['success' => false, 'message' => $sql]], $rows, 2);
$check('internal PMS error holds every record as unclear', count($unsure) === 2 && count($failed) === 0);
$check('internal PMS error shows no technical text', stripos($note . implode('', $unsure), 'SQLSTATE') === false);
[, $failed, $unsure, $note] = $im->invoke($s, ['status' => 'found', 'http' => 422, 'message' => 'The activity number is required', 'json' => ['success' => false, 'message' => 'The activity number is required']], $rows, 2);
$check('a plain refusal marks records not accepted', count($failed) === 2 && count($unsure) === 0);
[, $failed, $unsure] = $im->invoke($s, ['status' => 'unavailable', 'http' => 0, 'message' => 'No answer from PMS: Operation timed out', 'json' => null], $rows, 2);
$check('no answer holds records as unclear', count($unsure) === 2);
[, $failed] = $im->invoke($s, ['status' => 'found', 'http' => 401, 'message' => 'Unauthenticated.', 'json' => null], $rows, 2);
$check('a refused token marks records not accepted, in plain words', count($failed) === 2 && str_contains(reset($failed), 'access token'));

$mig = (string) file_get_contents(__DIR__ . '/../migrations/048_duplicate_protection.sql');
$check('migration 048 guards against existing duplicates', str_contains($mig, 'RAISE EXCEPTION') && str_contains($mig, 'IF NOT EXISTS'));
$award = (string) file_get_contents(__DIR__ . '/../src/VetBoard/Registration.php');
$check('award runs under a per-practitioner lock', str_contains($award, 'pg_advisory_xact_lock'));
$send = (string) file_get_contents(__DIR__ . '/../src/VetBoard/PmsPointsSender.php');
$check('send takes and releases a per-event lock', str_contains($send, 'pg_try_advisory_lock(7301') && str_contains($send, 'pg_advisory_unlock(7301'));

echo $fail === 0 ? "All checks passed.\n" : "$fail check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
