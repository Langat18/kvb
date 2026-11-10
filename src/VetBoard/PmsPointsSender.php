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
    private const RESOLVE_STALE = '7 days';

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
        $stmt = $this->pdo->prepare("SELECT reg_no, pms_reg_no, status FROM pms_resolutions WHERE reg_no IN ($in) AND checked_at > now() - interval '" . self::RESOLVE_STALE . "'");
        $stmt->execute(array_values($regNos));

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['reg_no']] = $r;
        }

        return $out;
    }

    /**
     * Looks up practitioners not yet checked, within a time budget. Returns how many remain unchecked.
     */
    public function resolve(array $regNos, int $budgetSeconds = 100): int
    {
        $known   = $this->resolutions($regNos);
        $pending = array_values(array_diff($regNos, array_keys($known)));
        $deadline = microtime(true) + $budgetSeconds;
        $save    = $this->pdo->prepare(
            'INSERT INTO pms_resolutions (reg_no, pms_reg_no, status, checked_at) VALUES (:r, :p, :s, now())
             ON CONFLICT (reg_no) DO UPDATE SET pms_reg_no = EXCLUDED.pms_reg_no, status = EXCLUDED.status, checked_at = now()'
        );

        foreach ($pending as $i => $reg) {
            if (microtime(true) > $deadline) {
                return count($pending) - $i;
            }

            $r = $this->client->registration($reg);

            if ($r['status'] === PmsClient::UNAVAILABLE) {
                return count($pending) - $i;
            }

            $found = $r['status'] === PmsClient::FOUND && !empty($r['data']['reg_number']);
            $save->execute([
                ':r' => $reg,
                ':p' => $found ? strtoupper(trim((string) $r['data']['reg_number'])) : null,
                ':s' => $found ? 'found' : 'not_found',
            ]);
        }

        return 0;
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
