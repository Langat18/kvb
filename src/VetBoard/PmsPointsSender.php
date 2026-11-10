<?php

declare(strict_types=1);

namespace VetBoard;

use PDO;

/**
 * Sends an event's CPD points to KVB PMS.
 * Each practitioner is first matched against PMS, and the number PMS holds is the one sent.
 * Every attempt is recorded, and a practitioner already sent (or of unknown outcome) is never sent again.
 */
final class PmsPointsSender
{
    private const CHUNK         = 50;

    private PDO $pdo;
    private PmsClient $client;

    public function __construct(?PmsClient $client = null, ?PDO $pdo = null)
    {
        $this->pdo    = $pdo ?? Database::getInstance()->getPdo();
        $this->client = $client ?? new PmsClient(null, null, 8, 3);
    }

    public function enabled(): bool
    {
        try {
            $stmt = $this->pdo->prepare('SELECT setting_value FROM integration_settings WHERE setting_key = :k');
            $stmt->execute([':k' => 'pms_points_send']);

            return $stmt->fetchColumn() === 'on';
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, array{pms_reg_no: ?string, status: string}> keyed by local reg_no */
    public function resolutions(array $regNos): array
    {
        if (!$regNos) {
            return [];
        }

        $in   = implode(',', array_fill(0, count($regNos), '?'));
        $stmt = $this->pdo->prepare("SELECT reg_no, pms_reg_no, status, detail FROM pms_resolutions WHERE reg_no IN ($in) AND checked_at > now() - CASE WHEN status = 'found' THEN interval '7 days' ELSE interval '1 day' END");
        $stmt->execute(array_values($regNos));

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['reg_no']] = $r;
        }

        return $out;
    }

    /**
     * Looks up the next few practitioners not yet checked. Returns how many still remain.
     * Called repeatedly by the page so no single request runs long.
     * A number PMS does not know is tried again in the other ways PMS may spell it (for example 0908 as 908 or VTA00908),
     * and a different spelling is accepted only when the name PMS holds matches ours.
     */
    public function resolve(array $regNos, int $limit = 30): int
    {
        $known   = $this->resolutions($regNos);
        $pending = array_values(array_diff($regNos, array_keys($known)));
        $batch   = array_slice($pending, 0, $limit);
        $replies = $this->client->registrations($batch);
        $save    = $this->pdo->prepare(
            'INSERT INTO pms_resolutions (reg_no, pms_reg_no, status, detail, checked_at) VALUES (:r, :p, :s, :d, now())
             ON CONFLICT (reg_no) DO UPDATE SET pms_reg_no = EXCLUDED.pms_reg_no, status = EXCLUDED.status, detail = EXCLUDED.detail, checked_at = now()'
        );

        $missing = [];
        foreach ($replies as $reg => $r) {
            if ($r['status'] === PmsClient::NOT_FOUND) {
                $missing[] = (string) $reg;
            }
        }

        $alt   = [];
        $local = [];
        if ($missing) {
            $in = implode(',', array_fill(0, count($missing), '?'));
            $st = $this->pdo->prepare("SELECT reg_no, name, id_number FROM participants WHERE reg_no IN ($in)");
            $st->execute($missing);
            foreach ($st->fetchAll() as $p) {
                $local[$p['reg_no']] = $p;
            }

            $want = [];
            foreach ($missing as $reg) {
                foreach (self::spellings($reg) as $c) {
                    $want[$c] = true;
                }
            }
            $alt = $want ? $this->client->registrations(array_map('strval', array_keys($want))) : [];
        }

        $suggest = $this->pdo->prepare(
            'INSERT INTO pms_suggestions (reg_no, candidate, pms_name, name_match, id_state) VALUES (:r, :c, :n, :m, :i)
             ON CONFLICT (reg_no, candidate) DO UPDATE SET pms_name = EXCLUDED.pms_name, name_match = EXCLUDED.name_match, id_state = EXCLUDED.id_state'
        );
        $approved = $this->pdo->prepare("SELECT candidate FROM pms_suggestions WHERE reg_no = :r AND status = 'approved' LIMIT 1");

        $saved = 0;
        foreach ($replies as $reg => $r) {
            $reg = (string) $reg;
            if ($r['status'] === PmsClient::UNAVAILABLE) {
                continue;
            }

            $pmsReg = null;
            $detail = null;

            if ($r['status'] === PmsClient::FOUND) {
                // PMS answered for this number; if it does not echo one back, the number we asked for is the one to send.
                $pmsReg = strtoupper(trim((string) ($r['data']['reg_number'] ?? ''))) ?: strtoupper(trim($reg));
            } else {
                $approved->execute([':r' => $reg]);
                if (($c = $approved->fetchColumn()) !== false) {
                    $pmsReg = (string) $c;
                    $detail = 'Approved by staff';
                } else {
                    $n = 0;
                    foreach (self::spellings($reg) as $c) {
                        $a = $alt[$c] ?? null;
                        if ($a && $a['status'] === PmsClient::FOUND) {
                            $d = (array) $a['data'];
                            $suggest->execute([
                                ':r' => $reg,
                                ':c' => strtoupper(trim((string) ($d['reg_number'] ?? ''))) ?: $c,
                                ':n' => substr(PmsAutofill::fullName($d), 0, 255),
                                ':m' => self::sameName((string) ($local[$reg]['name'] ?? ''), $d) ? 'true' : 'false',
                                ':i' => self::idState((string) ($local[$reg]['id_number'] ?? ''), PmsAutofill::idFrom($d)),
                            ]);
                            $n++;
                        }
                    }
                    $detail = substr((string) ($r['message'] ?? 'Not found') . ($n ? '. ' . $n . ' possible match(es) to review' : ''), 0, 200);
                }
            }

            $save->execute([':r' => $reg, ':p' => $pmsReg, ':s' => $pmsReg !== null ? 'found' : 'not_found', ':d' => $detail]);
            $saved++;
        }

        return count($pending) - $saved;
    }

    private static function idState(string $local, string $pms): string
    {
        $local = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $local));

        if ($pms === '' || $local === '') {
            return 'none';
        }

        return $local === $pms ? 'match' : (substr($local, 0, 4) === substr($pms, 0, 4) ? 'partial' : 'differs');
    }

    /** @return array<string, list<array{candidate: string, pms_name: string, name_match: bool, id_state: string}>> pending suggestions by local reg_no */
    public function suggestions(array $regNos): array
    {
        if (!$regNos) {
            return [];
        }

        $in = implode(',', array_fill(0, count($regNos), '?'));
        $st = $this->pdo->prepare("SELECT reg_no, candidate, pms_name, name_match, id_state FROM pms_suggestions WHERE status = 'pending' AND reg_no IN ($in) ORDER BY reg_no, candidate");
        $st->execute(array_values($regNos));

        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[$r['reg_no']][] = ['candidate' => $r['candidate'], 'pms_name' => (string) $r['pms_name'], 'name_match' => (bool) $r['name_match'], 'id_state' => $r['id_state']];
        }

        return $out;
    }

    /**
     * Accepts the chosen suggestions ("local>candidate"). Only suggestions that exist and are still pending are applied.
     * @return int how many were approved
     */
    public function approve(array $pairs, ?int $staffId): int
    {
        $n   = 0;
        $get = $this->pdo->prepare("SELECT 1 FROM pms_suggestions WHERE reg_no = :r AND candidate = :c AND status = 'pending'");
        $set = $this->pdo->prepare("UPDATE pms_suggestions SET status = 'approved', decided_by = :s, decided_at = now() WHERE reg_no = :r AND candidate = :c");
        $res = $this->pdo->prepare(
            "INSERT INTO pms_resolutions (reg_no, pms_reg_no, status, detail, checked_at) VALUES (:r, :c, 'found', 'Approved by staff', now())
             ON CONFLICT (reg_no) DO UPDATE SET pms_reg_no = EXCLUDED.pms_reg_no, status = 'found', detail = 'Approved by staff', checked_at = now()"
        );

        $this->pdo->beginTransaction();
        try {
            foreach ($pairs as $pair) {
                [$r, $c] = array_pad(explode('>', (string) $pair, 2), 2, '');
                $get->execute([':r' => $r, ':c' => $c]);
                if ($get->fetchColumn() === false) {
                    continue;
                }
                // Only one candidate can stand for a person.
                $this->pdo->prepare("UPDATE pms_suggestions SET status = 'pending', decided_by = NULL, decided_at = NULL WHERE reg_no = :r AND status = 'approved'")->execute([':r' => $r]);
                $set->execute([':s' => $staffId, ':r' => $r, ':c' => $c]);
                $res->execute([':r' => $r, ':c' => $c]);
                $n++;
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $n;
    }

    /** Other ways PMS may hold the same number. */
    private static function spellings(string $reg): array
    {
        $reg = strtoupper(trim($reg));
        if (!preg_match('/^(?:VT[ABC])?(\d{1,5})$/', $reg, $m)) {
            return [];
        }

        $d   = ltrim($m[1], '0') ?: '0';
        $out = [$d, str_pad($d, 4, '0', STR_PAD_LEFT), 'VTA' . str_pad($d, 5, '0', STR_PAD_LEFT), 'VTB' . str_pad($d, 5, '0', STR_PAD_LEFT), 'VTC' . str_pad($d, 5, '0', STR_PAD_LEFT)];

        return array_values(array_diff(array_unique($out), [$reg]));
    }

    private static function sameName(string $local, array $pms): bool
    {
        $tok = static fn(string $v): array => array_values(array_filter(preg_split('/[^a-z0-9]+/', strtolower($v)) ?: [], static fn($t) => strlen($t) > 1));
        $a   = $tok($local);
        $b   = $tok(trim(($pms['first_name'] ?? '') . ' ' . ($pms['last_name'] ?? '') . ' ' . ($pms['surname'] ?? '')));

        if (!$a || !$b) {
            return false;
        }

        $common = count(array_intersect($a, $b));

        return $common >= min(2, count($a), count($b));
    }

    /** Forgets "not found" answers for these people so they are looked up again. */
    public function recheck(array $regNos): void
    {
        if ($regNos) {
            $in = implode(',', array_fill(0, count($regNos), '?'));
            $this->pdo->prepare("DELETE FROM pms_resolutions WHERE status = 'not_found' AND reg_no IN ($in)")->execute(array_values($regNos));
            $this->pdo->prepare("DELETE FROM pms_suggestions WHERE status = 'pending' AND reg_no IN ($in)")->execute(array_values($regNos));
        }
    }

    /** @return array<string, array{status: string, response: ?string, created_at: string}> latest live row per reg_no */
    public function sentFor(int $eventId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT ON (reg_no) reg_no, status, response, created_at
             FROM pms_points_sends WHERE event_id = :e ORDER BY reg_no, (status <> 'failed') DESC, id DESC"
        );
        $stmt->execute([':e' => $eventId]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['reg_no']] = $r;
        }

        return $out;
    }

    /**
     * @param list<array{reg_no: string, pms_reg_no: string, points: float, hours: float}> $items
     * @return array{sent: int, failed: int, uncertain: int, message: string}
     */
    public function send(int $eventId, string $activityNumber, string $date, string $title, string $provider, array $items, ?int $staffId): array
    {
        $batch  = date('YmdHis') . '-' . bin2hex(random_bytes(3));
        $result = ['sent' => 0, 'failed' => 0, 'uncertain' => 0, 'message' => ''];
        $num    = static fn(float $v) => fmod($v, 1.0) === 0.0 ? (int) $v : round($v, 2);

        foreach (array_chunk($items, self::CHUNK) as $chunk) {
            // Reserve the rows first. The unique index refuses anyone already sent or of unknown outcome.
            $ids    = [];
            $insert = $this->pdo->prepare(
                "INSERT INTO pms_points_sends (event_id, reg_no, pms_reg_no, activity_number, cpd_points, cpd_hours, status, batch_ref, sent_by)
                 VALUES (:e, :r, :p, :a, :pt, :h, 'pending', :b, :s)
                 ON CONFLICT DO NOTHING RETURNING id"
            );
            $records = [];
            $rowOf   = [];

            foreach ($chunk as $it) {
                $insert->execute([
                    ':e' => $eventId, ':r' => $it['reg_no'], ':p' => $it['pms_reg_no'], ':a' => $activityNumber,
                    ':pt' => $it['points'], ':h' => $it['hours'], ':b' => $batch, ':s' => $staffId,
                ]);
                $id = $insert->fetchColumn();
                if ($id === false) {
                    continue;
                }

                $ids[]                    = (int) $id;
                $rowOf[$it['pms_reg_no']] = (int) $id;
                $records[]                = [
                    'record_sn'        => 'CPD-' . $eventId . '-' . $it['reg_no'],
                    'kvb_reg_number'   => $it['pms_reg_no'],
                    'activity_number'  => $activityNumber,
                    'activity_title'   => $title,
                    'date_of_activity' => $date,
                    'cpd_hours'        => $num($it['hours']),
                    'cpd_points'       => $num($it['points']),
                ];
            }

            if (!$records) {
                continue;
            }

            $reply = $this->client->uploadPoints($records, $provider);
            [$sent, $failed, $unsure, $note] = $this->interpret($reply, $rowOf, count($records));

            $upd = $this->pdo->prepare('UPDATE pms_points_sends SET status = :s, response = :r, updated_at = now() WHERE id = :id');
            foreach ($rowOf as $pmsReg => $id) {
                $state = isset($failed[$pmsReg]) ? 'failed' : (isset($unsure[$pmsReg]) ? 'uncertain' : 'sent');
                $upd->execute([':s' => $state, ':r' => $failed[$pmsReg] ?? ($unsure[$pmsReg] ?? $note), ':id' => $id]);
                $result[$state === 'sent' ? 'sent' : $state]++;
            }

            $result['message'] = $note;
        }

        return $result;
    }

    /**
     * Reads PMS's reply for one batch.
     * @return array{0: array, 1: array<string,string>, 2: array<string,string>, 3: string} [sent, failed by pms reg, uncertain by pms reg, note]
     */
    private function interpret(array $reply, array $rowOf, int $count): array
    {
        $failed = [];
        $unsure = [];

        if (in_array($reply['http'], [401, 403], true)) {
            // The token was refused, so nothing was credited and a retry is safe once the token is fixed.
            foreach (array_keys($rowOf) as $reg) {
                $failed[(string) $reg] = 'PMS refused the access token (HTTP ' . $reply['http'] . ')';
            }

            return [[], $failed, $unsure, 'PMS refused the access token. Ask KVB for a current token.'];
        }

        if ($reply['status'] !== PmsClient::FOUND) {
            // No clear answer: PMS may or may not have credited these, so they are held, never retried blindly.
            foreach (array_keys($rowOf) as $reg) {
                $unsure[(string) $reg] = (string) $reply['message'];
            }

            return [[], $failed, $unsure, (string) $reply['message']];
        }

        $json = $reply['json'];
        $d    = is_array($json['data'] ?? null) ? $json['data'] : [];
        $errs = $json['errors'] ?? $d['errors'] ?? [];
        $proc = $json['processed'] ?? $d['processed'] ?? null;
        $note = (string) ($reply['message'] ?? '');

        foreach (is_array($errs) ? $errs : [] as $e) {
            $reg = strtoupper(trim((string) ($e['registration_number'] ?? $e['kvb_reg_number'] ?? '')));
            if ($reg !== '' && isset($rowOf[$reg])) {
                $failed[$reg] = (string) ($e['message'] ?? 'Rejected by PMS');
            }
        }

        if (($json['success'] ?? false) !== true && $proc === null && !$failed) {
            // The whole batch was refused and nothing was credited.
            foreach (array_keys($rowOf) as $reg) {
                $failed[(string) $reg] = $note !== '' ? $note : 'Batch refused by PMS';
            }

            return [[], $failed, $unsure, $note];
        }

        if ($proc !== null && (int) $proc + count($failed) !== $count) {
            // The counts do not add up, so the outcome of the unlisted rows is not certain.
            foreach (array_keys($rowOf) as $reg) {
                if (!isset($failed[(string) $reg])) {
                    $unsure[(string) $reg] = 'PMS counts did not match: processed ' . (int) $proc . ' of ' . $count;
                }
            }
        }

        return [[], $failed, $unsure, $note];
    }

    /** Lets staff retry rows held as uncertain after they have checked PMS. */
    public function releaseUncertain(int $eventId): int
    {
        $stmt = $this->pdo->prepare("UPDATE pms_points_sends SET status = 'failed', response = 'Released by staff for retry', updated_at = now() WHERE event_id = :e AND status = 'uncertain'");
        $stmt->execute([':e' => $eventId]);

        return $stmt->rowCount();
    }
}
