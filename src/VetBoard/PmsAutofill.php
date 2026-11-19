<?php

declare(strict_types=1);

namespace VetBoard;

use PDO;

final class PmsAutofill
{
    private const ID_KEYS = ['id_passport_number', 'id_number', 'national_id', 'id_no', 'idno', 'national_id_number', 'identification_number', 'id_card_number'];
    private const IP_FAILS_PER_10_MIN  = 30;
    private const REG_FAILS_PER_HOUR   = 5;
    private const IP_PROBES_PER_10_MIN = 400;

    private PDO $pdo;
    private PmsClient $client;

    public function __construct(?PmsClient $client = null, ?PDO $pdo = null)
    {
        $this->pdo    = $pdo ?? Database::getInstance()->getPdo();
        $this->client = $client ?? new PmsClient(null, null, 6, 3);
    }

    public function enabled(): bool
    {
        try {
            $stmt = $this->pdo->prepare('SELECT setting_value FROM integration_settings WHERE setting_key = :k');
            $stmt->execute([':k' => 'pms_registration_autofill']);

            return $stmt->fetchColumn() === 'on';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 'last' or 'first' when the ID check is switched on (setting pms_id_check = last4 / first4), otherwise null.
     * Leave it off until KVB returns an ID number in the registration reply.
     */
    public function idMode(): ?string
    {
        try {
            $stmt = $this->pdo->prepare('SELECT setting_value FROM integration_settings WHERE setting_key = :k');
            $stmt->execute([':k' => 'pms_id_check']);
            $v = $stmt->fetchColumn();
            $v = $v === false ? 'first4' : $v;

            return $v === 'last4' ? 'last' : ($v === 'first4' ? 'first' : null);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Other ways PMS may hold the same number (padding, and VTA/VTB/VTC with 4 or 5 digits).
     * $wide lets a number typed without a prefix match any prefix; a typed prefix is kept, because
     * VTA, VTB and VTC numbers belong to different people.
     * @return list<string>
     */
    public static function variants(string $reg, bool $wide = true): array
    {
        $reg = strtoupper(trim($reg));
        if (!preg_match('/^(VT[ABC])?(\d{1,5})$/', $reg, $m)) {
            return [];
        }

        $d      = ltrim($m[2], '0') ?: '0';
        $prefix = $m[1];
        $pad4   = str_pad($d, 4, '0', STR_PAD_LEFT);
        $pad5   = str_pad($d, 5, '0', STR_PAD_LEFT);

        if ($prefix !== '') {
            $out = [$prefix . $pad4, $prefix . $pad5];
        } else {
            $out = [$d, $pad4, $pad5];
            if ($wide) {
                foreach (['VTA', 'VTB', 'VTC'] as $p) {
                    $out[] = $p . $pad4;
                    $out[] = $p . $pad5;
                }
            }
        }

        return array_values(array_diff(array_unique($out), [$reg]));
    }

    /**
     * Finds out who a registration number belongs to, without revealing personal details.
     * The person already in this system wins; otherwise the KVB register is asked, trying the usual spellings.
     * @return array{source: string, reg_no?: string, participant?: array, data?: array}
     *         source: local, pms, none, ambiguous or unavailable
     */
    private function locate(string $regNo): array
    {
        $local = $this->participant($regNo);
        if ($local) {
            return ['source' => 'local', 'reg_no' => (string) $local['reg_no'], 'participant' => $local];
        }

        if (!$this->enabled()) {
            return ['source' => 'none'];
        }

        $asked = array_merge([$regNo], self::variants($regNo, true));
        $found = [];
        $down  = 0;

        foreach ($this->client->registrations($asked) as $asking => $r) {
            if ($r['status'] === PmsClient::UNAVAILABLE) {
                $down++;
            } elseif ($r['status'] === PmsClient::FOUND && is_array($r['data'])) {
                $canon = strtoupper(trim((string) ($r['data']['reg_number'] ?? ''))) ?: strtoupper((string) $asking);
                $found[$canon] = $r['data'];
            }
        }

        if (!$found) {
            return ['source' => $down > 0 ? 'unavailable' : 'none'];
        }

        if (count($found) > 1) {
            if (!isset($found[$regNo])) {
                return ['source' => 'ambiguous'];
            }
            $found = [$regNo => $found[$regNo]];
        }

        $canon = (string) array_key_first($found);
        $local = $this->participant($canon);

        if ($local) {
            return ['source' => 'local', 'reg_no' => (string) $local['reg_no'], 'participant' => $local];
        }

        return ['source' => 'pms', 'reg_no' => $canon, 'data' => $found[$canon]];
    }

    /**
     * Step one of registration: is this number known, and under which spelling?
     * Nothing about the person is returned apart from a masked name for someone already in this system.
     * @return array{status: string, reg_no?: string, participant?: array}
     *         status: local, pms, not_found, ambiguous, unavailable, limited or invalid
     */
    public function resolve(string $regNo, string $ip): array
    {
        $regNo = strtoupper(trim($regNo));

        if (!Participant::isValidRegNo($regNo)) {
            return ['status' => 'invalid'];
        }

        if ($this->probeLimited($ip)) {
            return ['status' => 'limited'];
        }

        $w = $this->locate($regNo);

        if ($w['source'] === 'local') {
            return ['status' => 'local', 'reg_no' => $w['reg_no'], 'participant' => $w['participant']];
        }

        // Only a lookup that reached the KVB register is logged, so it can be capped per address.
        if ($this->enabled()) {
            $this->log($ip, $regNo, 'probe');
        }

        return match ($w['source']) {
            'pms'         => ['status' => 'pms', 'reg_no' => $w['reg_no']],
            'ambiguous'   => ['status' => 'ambiguous'],
            'unavailable' => ['status' => 'unavailable'],
            default       => ['status' => 'not_found'],
        };
    }

    /**
     * The person proves who they are with four characters of their ID number, checked against the KVB
     * register, or against the ID already on file for someone in this system.
     * The ID itself is never returned to the browser. A missing ID and a wrong one give the same reply.
     * @return array{status: string, reg_no?: string, source?: string, id?: string, data?: array{name: string, email: string, phone: string}}
     */
    public function verifyId(string $regNo, string $part, string $ip): array
    {
        $regNo = strtoupper(trim($regNo));
        $part  = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $part));
        $mode  = $this->idMode();

        if ($mode === null || !Participant::isValidRegNo($regNo) || strlen($part) !== 4) {
            return ['status' => 'invalid'];
        }

        if ($this->limited($regNo, $ip)) {
            $this->log($ip, $regNo, 'limited');

            return ['status' => 'limited'];
        }

        $w = $this->locate($regNo);

        if ($w['source'] === 'unavailable') {
            $this->log($ip, $regNo, 'unavailable');

            return ['status' => 'unavailable'];
        }

        if ($w['source'] === 'none' || $w['source'] === 'ambiguous') {
            $this->log($ip, $regNo, 'not_found');

            return ['status' => $w['source'] === 'none' ? 'not_found' : 'ambiguous'];
        }

        $local = $w['source'] === 'local';
        $d     = $local ? $w['participant'] : $w['data'];
        $id    = $local ? self::normaliseId((string) $d['id_number']) : self::idFrom($d);

        $expected = strlen($id) >= 6 ? ($mode === 'last' ? substr($id, -4) : substr($id, 0, 4)) : '';

        if ($expected === '' || !hash_equals($expected, $part)) {
            // Counted as a failed try either way, so the reply and the rate limit reveal nothing about the ID.
            $this->log($ip, $regNo, 'bad_dob');

            return ['status' => 'mismatch'];
        }

        $this->log($ip, $regNo, 'ok');

        return [
            'status' => 'ok',
            'source' => $local ? 'local' : 'pms',
            'reg_no' => $w['reg_no'],
            'id'     => $id,
            'data'   => $local
                ? ['name' => (string) $d['name'], 'email' => (string) $d['email'], 'phone' => (string) ($d['phone'] ?? '')]
                : ['name' => self::fullName($d), 'email' => self::email((string) ($d['email'] ?? '')), 'phone' => trim((string) ($d['phone_number'] ?? ''))],
        ];
    }

    /** The visitor's address; a forwarded one is trusted only when the request came through our own proxy. */
    public static function clientIp(): string
    {
        $remote    = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $forwarded = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
        $viaProxy  = filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;

        return ($viaProxy && filter_var($forwarded, FILTER_VALIDATE_IP)) ? $forwarded : $remote;
    }

    /** Whether a full ID typed by hand is the one on file for this person (letters and digits only, any case). */
    public static function sameId(string $typed, string $onFile): bool
    {
        $a = self::normaliseId($typed);

        return $a !== '' && hash_equals(self::normaliseId($onFile), $a);
    }

    public static function normaliseId(string $v): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $v));
    }

    /** Counts a failed try for a number typed by hand against an ID on file, so it cannot be guessed. */
    public function failedTry(string $regNo, string $ip): void
    {
        $this->log($ip, strtoupper(trim($regNo)), 'bad_dob');
    }

    public function tooManyTries(string $regNo, string $ip): bool
    {
        return $this->limited(strtoupper(trim($regNo)), $ip);
    }

    public static function fullName(array $d): string
    {
        $parts = [];

        foreach (['first_name', 'last_name', 'surname'] as $k) {
            $p = trim((string) ($d[$k] ?? ''));

            if ($p !== '' && !in_array(mb_strtolower($p), array_map('mb_strtolower', $parts), true)) {
                $parts[] = $p;
            }
        }

        return implode(' ', $parts);
    }

    private static function email(string $v): string
    {
        $v = strtolower(trim($v));

        return filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : '';
    }

    /** The ID or passport number in a PMS registration record, letters and digits only, upper case. */
    public static function idFrom(array $data): string
    {
        foreach (self::ID_KEYS as $k) {
            $id = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($data[$k] ?? '')));
            if ($id !== '') {
                return $id;
            }
        }

        return '';
    }

    private function participant(string $regNo): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM participants WHERE reg_no = :r');
        $stmt->execute([':r' => $regNo]);

        return $stmt->fetch() ?: null;
    }

    /** Looking numbers up at the KVB register is capped per address; a venue behind one address still has room. */
    private function probeLimited(string $ip): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM pms_lookup_log WHERE ip = :ip AND at > now() - interval '10 minutes' AND outcome = 'probe'");
        $stmt->execute([':ip' => substr($ip, 0, 45)]);

        return (int) $stmt->fetchColumn() >= self::IP_PROBES_PER_10_MIN;
    }

    private function limited(string $regNo, string $ip): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                COUNT(*) FILTER (WHERE ip = :ip AND at > now() - interval '10 minutes' AND outcome IN ('bad_dob', 'not_found')) AS ip_fails,
                COUNT(*) FILTER (WHERE reg_no = :reg AND at > now() - interval '1 hour' AND outcome = 'bad_dob') AS reg_fails
             FROM pms_lookup_log
             WHERE at > now() - interval '1 hour'"
        );
        $stmt->execute([':ip' => $ip, ':reg' => $regNo]);
        $row = $stmt->fetch();

        return (int) $row['ip_fails'] >= self::IP_FAILS_PER_10_MIN || (int) $row['reg_fails'] >= self::REG_FAILS_PER_HOUR;
    }

    private function log(string $ip, string $regNo, string $outcome): void
    {
        $this->pdo->prepare('INSERT INTO pms_lookup_log (ip, reg_no, outcome) VALUES (:ip, :reg, :o)')
            ->execute([':ip' => substr($ip, 0, 45), ':reg' => $regNo, ':o' => $outcome]);

        if (random_int(1, 100) === 1) {
            $this->pdo->exec("DELETE FROM pms_lookup_log WHERE at < now() - interval '30 days'");
        }
    }
}
