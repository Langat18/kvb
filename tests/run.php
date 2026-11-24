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

// ---- Paginator ----
use VetBoard\Paginator;
$pg = new Paginator(133, ['page' => '3', 'per_page' => '20', 'search' => 'x y', 'event_mode' => '']);
$check('pages are counted', $pg->pages === 7 && $pg->page === 3 && $pg->offset === 40 && $pg->from === 41 && $pg->to === 60);
$check('a page past the end goes to the last page', (new Paginator(133, ['page' => '99']))->page === 7);
$check('page zero or junk goes to page 1', (new Paginator(133, ['page' => '0']))->page === 1 && (new Paginator(133, ['page' => 'abc']))->page === 1);
$check('an unlisted page size falls back to 20', (new Paginator(133, ['per_page' => '5000']))->perPage === 20);
$check('a listed page size is kept', (new Paginator(133, ['per_page' => '50']))->perPage === 50);
$check('no rows: one empty page, nothing shown', ($z = new Paginator(0, []))->pages === 1 && $z->from === 0 && $z->to === 0 && !$z->needed());
$check('a short list needs no pager', !(new Paginator(10, []))->needed() && (new Paginator(11, []))->needed());
$check('last page shows the remainder', (new Paginator(133, ['page' => '7']))->to === 133 && count((new Paginator(133, ['page' => '7']))->slice(range(1, 133))) === 13);
$check('slice returns the right rows', (new Paginator(133, ['page' => '2']))->slice(range(1, 133))[0] === 21);
$check('window shows ends and neighbours', (new Paginator(2000, ['page' => '50', 'per_page' => '10']))->window() === [1, null, 49, 50, 51, null, 200]);
$check('window on page 1 shows the first, the next and the last', (new Paginator(133, []))->window() === [1, 2, null, 7]);
$check('window does not hide a single page behind a gap', (new Paginator(133, ['page' => '4']))->window() === [1, 2, 3, 4, 5, 6, 7]);
$check('links keep filters and drop empty page 1', $pg->url(1) === '?search=x+y&event_mode=' && str_contains($pg->url(5), 'page=5') && str_contains($pg->url(5), 'search=x+y'));
$check('links drop the default page size', !str_contains($pg->url(2), 'per_page'));
$check('page size form carries filters but not page or size', $pg->carried() === ['search' => 'x y']);
$ps = new Paginator(900, ['pg' => '2', 'ps' => '50'], 'pg', 'ps');
$check('custom parameter names work (several lists on one page)', $ps->page === 2 && $ps->perPage === 50 && str_contains($ps->url(3), 'pg=3'));

echo $fail === 0 ? "All checks passed.\n" : "$fail check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
