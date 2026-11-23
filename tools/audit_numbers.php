<?php
// READ-ONLY audit. Finds participants whose stored ID differs from the ID the KVB register holds for the same
// registration number, and shows what each holds here (events, points) and whether points were already sent.
// Output uses registration numbers and masked IDs only.
//   php audit_numbers.php all        or        php audit_numbers.php EVENT_ID
declare(strict_types=1);
$root = getenv('APP_ROOT') ?: '/var/www/html';
require_once $root . '/vendor/autoload.php';
require_once $root . '/config/constants.php';
require_once $root . '/config/database.php';
use VetBoard\PmsClient;
$scope = $argv[1] ?? '';
if ($scope === '') { fwrite(STDERR, "Usage: php audit_numbers.php all|EVENT_ID\n"); exit(1); }
$pdo  = VetBoard\Database::getInstance()->getPdo();
$norm = static fn(string $v): string => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $v));
$mask = static fn(string $v): string => strlen($v) <= 4 ? str_repeat('*', strlen($v)) : substr($v, 0, 2) . str_repeat('*', strlen($v) - 4) . substr($v, -2);
$tok  = static fn(string $v): array => array_values(array_filter(preg_split('/[^a-z0-9]+/', strtolower($v)) ?: [], static fn($t) => strlen($t) > 1));
$keys = ['id_passport_number', 'id_number', 'national_id', 'id_no', 'idno', 'national_id_number', 'identification_number', 'id_card_number'];
if ($scope === 'all') {
    $rows = $pdo->query('SELECT reg_no, name, id_number FROM participants ORDER BY reg_no')->fetchAll();
} else {
    $st = $pdo->prepare('SELECT p.reg_no, p.name, p.id_number FROM participants p WHERE p.reg_no IN (SELECT reg_no FROM registrations WHERE event_id = :e) ORDER BY p.reg_no');
    $st->execute([':e' => (int) $scope]); $rows = $st->fetchAll();
}
$ev   = $pdo->query('SELECT reg_no, COUNT(*) c FROM registrations GROUP BY reg_no')->fetchAll(\PDO::FETCH_KEY_PAIR);
$pts  = $pdo->query('SELECT reg_no, SUM(points) p FROM points_ledger GROUP BY reg_no')->fetchAll(\PDO::FETCH_KEY_PAIR);
$sent = [];
if ($pdo->query("SELECT to_regclass('public.pms_points_sends') IS NOT NULL")->fetchColumn()) {
    $sent = $pdo->query("SELECT reg_no, COUNT(*) c FROM pms_points_sends WHERE status IN ('sent','uncertain') GROUP BY reg_no")->fetchAll(\PDO::FETCH_KEY_PAIR);
}
$client = new PmsClient();
$ok = $diff = $nf = 0; $out = []; $codes = [];
$todo = array_column($rows, null, 'reg_no');
// PMS can refuse a burst of requests, so people it could not answer are asked again, slowly, in a few rounds.
foreach ([[20, 3], [10, 2], [5, 1], [5, 1]] as $round => [$size, $conc]) {
    if (!$todo) { break; }
    if ($round > 0) { sleep(4); }
    $codes = [];
    foreach (array_chunk(array_keys($todo), $size) as $chunk) {
        $res = $client->registrations($chunk, $conc);
        foreach ($chunk as $reg) {
            $p = $todo[$reg];
            $r = $res[$reg] ?? ['status' => PmsClient::UNAVAILABLE, 'http' => 0];
            if ($r['status'] === PmsClient::UNAVAILABLE) { $codes[(string) ($r['http'] ?? 0)] = ($codes[(string) ($r['http'] ?? 0)] ?? 0) + 1; continue; }
            unset($todo[$reg]);
            if ($r['status'] !== PmsClient::FOUND || !is_array($r['data'])) { $nf++; continue; }
            $d = $r['data']; $pid = '';
            foreach ($keys as $k) { if (!empty($d[$k])) { $pid = $norm((string) $d[$k]); break; } }
            $pmsName = trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? '') . ' ' . ($d['surname'] ?? ''));
            $nameOk  = count(array_intersect($tok((string) $p['name']), $tok($pmsName))) >= 2;
            $idOk    = $pid !== '' && $pid === $norm((string) $p['id_number']);
            if ($idOk || ($pid === '' && $nameOk)) { $ok++; continue; }
            $diff++;
            $out[] = [$p['reg_no'], $mask($norm((string) $p['id_number'])), $pid !== '' ? $mask($pid) : 'none held', $nameOk ? 'yes' : 'NO', (int) ($ev[$p['reg_no']] ?? 0), (float) ($pts[$p['reg_no']] ?? 0), (int) ($sent[$p['reg_no']] ?? 0)];
        }
    }
}
$down = count($todo);
printf("Checked %d people. Same person as the register: %d. NOT the same: %d. Not on the register under that number: %d. Register unreachable: %d.\n\n", count($rows), $ok, $diff, $nf, $down);
if ($out) {
    printf("%-10s  %-13s  %-13s  %-10s  %-6s  %-8s  %s\n", 'REG NO', 'STORED ID', 'REGISTER ID', 'NAME MATCH', 'EVENTS', 'POINTS', 'UPLOADS SENT');
    foreach ($out as $x) { printf("%-10s  %-13s  %-13s  %-10s  %-6d  %-8s  %d\n", $x[0], $x[1], $x[2], $x[3], $x[4], number_format($x[5], 1), $x[6]); }
}
if ($down) { echo "\nCould not be checked after retries: $down. Reply codes from PMS (0 = no answer): " . json_encode($codes) . "\n"; }
