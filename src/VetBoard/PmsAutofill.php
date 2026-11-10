<?php

declare(strict_types=1);

namespace VetBoard;

use PDO;

final class PmsAutofill
{
    private const IP_FAILS_PER_10_MIN  = 30;
    private const REG_FAILS_PER_HOUR   = 5;

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

            return $v === 'last4' ? 'last' : ($v === 'first4' ? 'first' : null);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Same as verify(), but the person proves who they are with four characters of their ID number.
     * The ID itself is never returned. A missing ID on the register and a wrong one give the same reply.
     * @return array{status: string, data?: array{name: string, email: string, phone: string}}
     */
    public function verifyId(string $regNo, string $part, string $ip): array
    {
        $regNo = strtoupper(trim($regNo));
        $part  = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $part));
        $mode  = $this->idMode();

        if ($mode === null || !Participant::isValidNewRegNo($regNo) || strlen($part) !== 4) {
            return ['status' => 'invalid'];
        }

        if ($this->exists($regNo)) {
            return ['status' => 'local'];
        }

        if ($this->limited($regNo, $ip)) {
            $this->log($ip, $regNo, 'limited');

            return ['status' => 'limited'];
        }

        $r = $this->client->registration($regNo);

        if ($r['status'] === PmsClient::UNAVAILABLE) {
            $this->log($ip, $regNo, 'unavailable');

            return ['status' => 'unavailable'];
        }

        if ($r['status'] === PmsClient::NOT_FOUND) {
            $this->log($ip, $regNo, 'not_found');

            return ['status' => 'not_found'];
        }

        $id = '';
        foreach (['id_number', 'national_id', 'id_no'] as $k) {
            $id = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($r['data'][$k] ?? '')));
            if ($id !== '') {
                break;
            }
        }

        $expected = strlen($id) >= 6 ? ($mode === 'last' ? substr($id, -4) : substr($id, 0, 4)) : '';

        if ($expected === '' || !hash_equals($expected, $part)) {
            // Counted as a failed try either way, so the reply and the rate limit reveal nothing about the ID.
            $this->log($ip, $regNo, 'bad_dob');

            return ['status' => 'mismatch'];
        }

        $this->log($ip, $regNo, 'ok');
        $d = $r['data'];

        return ['status' => 'ok', 'data' => [
            'name'  => self::fullName($d),
            'email' => self::email((string) ($d['email'] ?? '')),
            'phone' => trim((string) ($d['phone_number'] ?? '')),
        ]];
    }

    /**
     * Returns the details of a person on the KVB register once the date of birth they typed matches.
     * @return array{status: string, data?: array{name: string, email: string, phone: string}}
     */
    public function verify(string $regNo, string $dob, string $ip): array
    {
        $regNo = strtoupper(trim($regNo));
        $dob   = self::normalizeDate($dob);

        if (!Participant::isValidNewRegNo($regNo) || $dob === null) {
            return ['status' => 'invalid'];
        }

        if ($this->exists($regNo)) {
            return ['status' => 'local'];
        }

        if ($this->limited($regNo, $ip)) {
            $this->log($ip, $regNo, 'limited');

            return ['status' => 'limited'];
        }

        $r = $this->client->registration($regNo);

        if ($r['status'] === PmsClient::UNAVAILABLE) {
            $this->log($ip, $regNo, 'unavailable');

            return ['status' => 'unavailable'];
        }

        if ($r['status'] === PmsClient::NOT_FOUND) {
            $this->log($ip, $regNo, 'not_found');

            return ['status' => 'not_found'];
        }

        $pmsDob = self::normalizeDate((string) ($r['data']['dob'] ?? ''));

        if ($pmsDob === null) {
            $this->log($ip, $regNo, 'unverifiable');

            return ['status' => 'unverifiable'];
        }

        if (!hash_equals($pmsDob, $dob)) {
            $this->log($ip, $regNo, 'bad_dob');

            return ['status' => 'mismatch'];
        }

        $this->log($ip, $regNo, 'ok');
        $d = $r['data'];

        return ['status' => 'ok', 'data' => [
            'name'  => self::fullName($d),
            'email' => self::email((string) ($d['email'] ?? '')),
            'phone' => trim((string) ($d['phone_number'] ?? '')),
        ]];
    }

    public static function normalizeDate(string $v): ?string
    {
        $v = trim($v);

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})#', $v, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        return checkdate($mo, $d, $y) && $y > 1900 ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
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

    private function exists(string $regNo): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM participants WHERE reg_no = :r');
        $stmt->execute([':r' => $regNo]);

        return (bool) $stmt->fetchColumn();
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
