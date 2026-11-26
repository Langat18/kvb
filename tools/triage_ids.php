<?php
// Sorts the people flagged by audit_numbers.php and, only when asked, corrects obvious ID typos from the KVB register.
//   php triage_ids.php FILE dry      -> report only; changes nothing
//   php triage_ids.php FILE apply    -> corrects the "FIX" group (each old ID is saved first in participant_id_fixes)
// FILE holds one registration number per line. Output shows registration numbers and masked IDs only.
// FIX group: the register holds an ID, the name matches (2+ shared words) and the IDs differ by at most 2 characters
// (or the stored ID is clearly incomplete), and the register's ID is not already held by someone else here.
declare(strict_types=1);
$root = getenv('APP_ROOT') ?: '/var/www/html';
require_once $root . '/vendor/autoload.php';
require_once $root . '/config/constants.php';
require_once $root . '/config/database.php';
use VetBoard\PmsClient;

$file  = $argv[1] ?? '';
$apply = ($argv[2] ?? 'dry') === 'apply';
if ($file === '' || !is_readable($file)) { fwrite(STDERR, "Usage: php triage_ids.php FILE dry|apply\n"); exit(1); }
$regs = array_values(array_unique(array_filter(array_map(static fn($l) => strtoupper(trim($l)), file($file) ?: []))));

$pdo  = VetBoard\Database::getInstance()->getPdo();
$norm = static fn(string $v): string => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $v));
$mask = static fn(string $v): string => strlen($v) <= 4 ? str_repeat('*', strlen($v)) : substr($v, 0, 2) . str_repeat('*', strlen($v) - 4) . substr($v, -2);
$tok  = static fn(string $v): array => array_values(array_filter(preg_split('/[^a-z0-9]+/', strtolower($v)) ?: [], static fn($t) => strlen($t) > 1));
$keys = ['id_passport_number', 'id_number', 'national_id', 'id_no', 'idno', 'national_id_number', 'identification_number', 'id_card_number'];

$in = implode(',', array_fill(0, count($regs), '?'));
$st = $pdo->prepare("SELECT p.reg_no, p.name, p.id_number, (SELECT COUNT(*) FROM registrations r WHERE r.reg_no = p.reg_no) AS ev,
                     COALESCE((SELECT SUM(points) FROM points_ledger l WHERE l.reg_no = p.reg_no), 0) AS pts FROM participants p WHERE p.reg_no IN ($in)");
$st->execute($regs);
$mine = array_column($st->fetchAll(), null, 'reg_no');

$holders = [];
foreach ($pdo->query('SELECT reg_no, id_number FROM participants')->fetchAll() as $p) { $holders[$norm((string) $p['id_number'])][] = $p['reg_no']; }

$client = new PmsClient();
$pms = []; $todo = array_keys($mine);
foreach ([[10, 2], [5, 1], [5, 1]] as $i => [$size, $conc]) {
    if (!$todo) { break; }
    if ($i > 0) { sleep(4); }
    foreach (array_chunk($todo, $size) as $chunk) {
        $res = $client->registrations($chunk, $conc);
        foreach ($chunk as $reg) {
            $r = $res[$reg] ?? ['status' => PmsClient::UNAVAILABLE];
            if ($r['status'] !== PmsClient::UNAVAILABLE) { $pms[$reg] = $r; $todo = array_values(array_diff($todo, [$reg])); }
        }
    }
}

$groups = []; $fix = [];
foreach ($mine as $reg => $p) {
    $r = $pms[$reg] ?? null;
    if ($r === null) { $groups['0 NOT CHECKED (PMS did not answer)'][] = [$reg, '', '', $p]; continue; }
    if ($r['status'] !== PmsClient::FOUND || !is_array($r['data'])) { $groups['0 NOT ON THE REGISTER under this number'][] = [$reg, '', '', $p]; continue; }
    $d = $r['data']; $pid = '';
    foreach ($keys as $k) { if (!empty($d[$k])) { $pid = $norm((string) $d[$k]); $raw = trim((string) $d[$k]); break; } }
    $mineId  = $norm((string) $p['id_number']);
    $nameOk  = count(array_intersect($tok((string) $p['name']), $tok(trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? '') . ' ' . ($d['surname'] ?? '')))) ) >= 2;
    if ($pid === '') { $groups['3 REVIEW: the register holds no ID, so the name is the only check'][] = [$reg, $mask($mineId), 'none', $p]; continue; }
    if ($pid === $mineId) { continue; }
    $dist = levenshtein($mineId, $pid);
    $incomplete = strlen($mineId) < 6;
    $others = array_values(array_diff($holders[$pid] ?? [], [$reg]));
    if ($nameOk && ($dist <= 2 || $incomplete)) {
        if ($others) { $groups['2 REVIEW: would be a fix, but the register ID is held here by reg no ' . implode(',', $others)][] = [$reg, $mask($mineId), $mask($pid), $p]; continue; }
        $groups['1 FIX: same person, stored ID has a typo or is incomplete'][] = [$reg, $mask($mineId), $mask($pid), $p];
        $fix[$reg] = [(string) $p['id_number'], $raw];
    } elseif ($nameOk) {
        $groups['2 REVIEW: name matches but the IDs are very different'][] = [$reg, $mask($mineId), $mask($pid), $p];
    } elseif ($dist <= 2) {
        $groups['3 REVIEW: name differs, ID nearly the same (spelling of the name?)'][] = [$reg, $mask($mineId), $mask($pid), $p];
    } else {
        $groups['4 POSSIBLE WRONG PERSON: name and ID both differ'][] = [$reg, $mask($mineId), $mask($pid), $p];
    }
}

ksort($groups);
foreach ($groups as $title => $list) {
    printf("\n== %s: %d\n", $title, count($list));
    foreach ($list as [$reg, $a, $b, $p]) { printf("  %-10s stored %-12s register %-12s events %d  points %s\n", $reg, $a, $b, (int) $p['ev'], number_format((float) $p['pts'], 1)); }
}
printf("\n%d people in the FIX group. Mode: %s\n", count($fix), $apply ? 'APPLY' : 'dry run (nothing changed)');

if ($apply && $fix) {
    $pdo->exec('CREATE TABLE IF NOT EXISTS participant_id_fixes (id BIGSERIAL PRIMARY KEY, reg_no VARCHAR(10) NOT NULL, old_id VARCHAR(20) NOT NULL, new_id VARCHAR(20) NOT NULL, fixed_at TIMESTAMPTZ NOT NULL DEFAULT now())');
    $pdo->beginTransaction();
    $log = $pdo->prepare('INSERT INTO participant_id_fixes (reg_no, old_id, new_id) VALUES (:r, :o, :n)');
    $upd = $pdo->prepare('UPDATE participants SET id_number = :n WHERE reg_no = :r AND id_number = :o');
    $done = 0;
    foreach ($fix as $reg => [$old, $new]) {
        $new = mb_substr($new, 0, 20);
        $log->execute([':r' => $reg, ':o' => $old, ':n' => $new]);
        $upd->execute([':n' => $new, ':r' => $reg, ':o' => $old]);
        $done += $upd->rowCount();
    }
    $pdo->commit();
    echo "Corrected $done. Old IDs are saved in participant_id_fixes.\n";
}
