<?php

declare(strict_types=1);

namespace VetBoard;

use PDO;

class Participant
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getPdo();
    }

    // Lenient format check used for lookup/attendance. Accepts both the
    // legacy 4-digit VT-prefixed format and the current 5-digit format
    public static function isValidRegNo(string $regNo): bool
    {
        return (bool) preg_match('/^(\d{4,5}|VT[ABC]\d{4,5})$/', $regNo);
    }

    // Strict check enforced only when creating a NEW registration. VT-
    // prefixed codes must be 5 digits going forward (VTAXXXXX / VTBXXXXX /
    // VTCXXXXX); the plain 4-digit numeric format is unchanged.
    public static function isValidNewRegNo(string $regNo): bool
    {
        return (bool) preg_match('/^(\d{4,5}|VT[ABC]\d{5})$/', $regNo);
    }

    public function create(array $data): string
    {
        $regNo = strtoupper(trim($data['reg_no'] ?? ''));

        if (!self::isValidNewRegNo($regNo)) {
            throw new \InvalidArgumentException('Invalid registration number format.');
        }

        if (empty($data['name'])) {
            throw new \InvalidArgumentException('Name is required.');
        }

        if (empty($data['id_number'])) {
            throw new \InvalidArgumentException('ID number is required.');
        }

        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid email is required.');
        }

        if ($this->findByRegNo($regNo)) {
            throw new \InvalidArgumentException('This registration number is already recorded.');
        }

        if ($this->findByEmail($data['email'])) {
            throw new \InvalidArgumentException('This email address is already registered under a different RegNo.');
        }

        $existingByIdNumber = $this->findByIdNumber($data['id_number']);
        if ($existingByIdNumber) {
            throw new \InvalidArgumentException(
                'This ID number is already registered under Reg. No. ' . $existingByIdNumber['reg_no'] . '. Please use that number to sign in instead.'
            );
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO participants (reg_no, name, id_number, email, phone)
             VALUES (:reg_no, :name, :id_number, :email, :phone)'
        );

        $stmt->execute([
            ':reg_no'    => $regNo,
            ':name'      => trim($data['name']),
            ':id_number' => trim($data['id_number']),
            ':email'     => strtolower(trim($data['email'])),
            ':phone'     => trim($data['phone'] ?? ''),
        ]);

        return $regNo;
    }

    public function findByRegNoAndIdNumber(string $regNo, string $idNumber): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM participants WHERE reg_no = :reg_no AND id_number = :id_number');
        $stmt->execute([
            ':reg_no'    => strtoupper(trim($regNo)),
            ':id_number' => trim($idNumber),
        ]);

        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function pointsSummary(string $regNo): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.title, e.start_date, pl.points, pl.reason, pl.awarded_at
             FROM points_ledger pl
             JOIN events e ON e.id = pl.event_id
             WHERE pl.reg_no = :reg_no
             ORDER BY pl.awarded_at DESC'
        );
        $stmt->execute([':reg_no' => strtoupper(trim($regNo))]);

        return $stmt->fetchAll();
    }

    public function totalPoints(string $regNo): float
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(points), 0) FROM points_ledger WHERE reg_no = :reg_no');
        $stmt->execute([':reg_no' => strtoupper(trim($regNo))]);

        return (float) $stmt->fetchColumn();
    }

    public function findByRegNo(string $regNo): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM participants WHERE reg_no = :reg_no');
        $stmt->execute([':reg_no' => strtoupper(trim($regNo))]);

        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM participants WHERE email = :email');
        $stmt->execute([':email' => strtolower(trim($email))]);

        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findByIdNumber(string $idNumber): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM participants WHERE id_number = :id_number');
        $stmt->execute([':id_number' => trim($idNumber)]);

        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function update(string $regNo, array $data): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE participants
             SET name = :name, id_number = :id_number, email = :email, phone = :phone
             WHERE reg_no = :reg_no'
        );

        return $stmt->execute([
            ':reg_no'    => strtoupper(trim($regNo)),
            ':name'      => trim($data['name']),
            ':id_number' => trim($data['id_number']),
            ':email'     => strtolower(trim($data['email'])),
            ':phone'     => trim($data['phone'] ?? ''),
        ]);
    }

    public function updateDetails(string $oldRegNo, array $data, ?int $staffId, ?int $eventId = null): array
    {
        $oldRegNo = strtoupper(trim($oldRegNo));
        $newRegNo = strtoupper(trim((string) ($data['reg_no'] ?? '')));
        $name     = trim((string) ($data['name'] ?? ''));
        $email    = strtolower(trim((string) ($data['email'] ?? '')));
        $phone    = trim((string) ($data['phone'] ?? ''));

        if ($name === '' || $phone === '') {
            return ['ok' => false, 'message' => 'Name and phone are required.', 'reg_no' => $oldRegNo];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Please enter a valid email address.', 'reg_no' => $oldRegNo];
        }

        if ($newRegNo !== $oldRegNo && !self::isValidNewRegNo($newRegNo)) {
            return ['ok' => false, 'message' => 'Invalid registration number format. Expected 4 or 5 digits, VTAXXXXX, VTBXXXXX or VTCXXXXX.', 'reg_no' => $oldRegNo];
        }

        try {
            $this->pdo->beginTransaction();

            $cur = $this->pdo->prepare('SELECT name, email, phone FROM participants WHERE reg_no = :r FOR UPDATE');
            $cur->execute([':r' => $oldRegNo]);
            $row = $cur->fetch();

            if (!$row) {
                $this->pdo->rollBack();
                return ['ok' => false, 'message' => 'That attendee no longer exists.', 'reg_no' => $oldRegNo];
            }

            if ($newRegNo !== $oldRegNo) {
                $dup = $this->pdo->prepare('SELECT 1 FROM participants WHERE reg_no = :r');
                $dup->execute([':r' => $newRegNo]);

                if ($dup->fetchColumn()) {
                    $this->pdo->rollBack();
                    return ['ok' => false, 'message' => 'Registration number ' . $newRegNo . ' already belongs to another attendee. Two records for one person need a separate fix, not an edit.', 'reg_no' => $oldRegNo];
                }
            }

            $dupEmail = $this->pdo->prepare('SELECT reg_no FROM participants WHERE email = :e AND reg_no <> :r');
            $dupEmail->execute([':e' => $email, ':r' => $oldRegNo]);
            $other = $dupEmail->fetchColumn();

            if ($other) {
                $this->pdo->rollBack();
                return ['ok' => false, 'message' => 'That email address already belongs to attendee ' . $other . '.', 'reg_no' => $oldRegNo];
            }

            $changes = [];
            foreach ([
                ['reg_no', $oldRegNo, $newRegNo],
                ['name', (string) $row['name'], $name],
                ['email', (string) $row['email'], $email],
                ['phone', (string) ($row['phone'] ?? ''), $phone],
            ] as [$field, $old, $new]) {
                if ($old !== $new) {
                    $changes[] = [$field, $old, $new];
                }
            }

            if (!$changes) {
                $this->pdo->rollBack();
                return ['ok' => true, 'message' => 'No changes were made.', 'reg_no' => $oldRegNo];
            }

            $upd = $this->pdo->prepare(
                'UPDATE participants SET reg_no = :new_r, name = :name, email = :email, phone = :phone WHERE reg_no = :old_r'
            );
            $upd->execute([':new_r' => $newRegNo, ':name' => $name, ':email' => $email, ':phone' => $phone, ':old_r' => $oldRegNo]);

            $aud = $this->pdo->prepare(
                'INSERT INTO participant_edit_audit (reg_no_before, reg_no_after, field, old_value, new_value, changed_by, event_id)
                 VALUES (:b, :a, :f, :o, :n, :s, :e)'
            );
            foreach ($changes as [$field, $old, $new]) {
                $aud->bindValue(':b', $oldRegNo);
                $aud->bindValue(':a', $newRegNo);
                $aud->bindValue(':f', $field);
                $aud->bindValue(':o', $old);
                $aud->bindValue(':n', $new);
                $aud->bindValue(':s', $staffId, $staffId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $aud->bindValue(':e', $eventId, $eventId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $aud->execute();
            }

            $this->pdo->commit();
            error_log(sprintf('[attendee-edit] reg_no=%s->%s staff_id=%s fields=%s', $oldRegNo, $newRegNo, $staffId ?? 'none', implode(',', array_column($changes, 0))));

            return ['ok' => true, 'message' => 'Details updated for ' . $newRegNo . '.', 'reg_no' => $newRegNo];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('[attendee-edit] failed for ' . $oldRegNo . ': ' . $e->getMessage());

            return ['ok' => false, 'message' => 'A database error stopped this change. Nothing was changed.', 'reg_no' => $oldRegNo];
        }
    }

    public function leaderboard(string $search = '', int $limit = 50, int $offset = 0): array
    {
        $where  = '';
        $params = [];

        if ($search !== '') {
            $where = 'WHERE p.name ILIKE :search OR p.reg_no ILIKE :search';
            $params[':search'] = '%' . $search . '%';
        }

        $stmt = $this->pdo->prepare(
            "SELECT p.reg_no, p.name, p.cadre, COALESCE(SUM(pl.points), 0) AS total_points
             FROM participants p
             LEFT JOIN points_ledger pl ON pl.reg_no = p.reg_no
             {$where}
             GROUP BY p.reg_no, p.name, p.cadre
             ORDER BY total_points DESC, p.name ASC
             LIMIT :limit OFFSET :offset"
        );

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function list(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]           = '(name ILIKE :search OR reg_no ILIKE :search OR email ILIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['cadre'])) {
            $where[]          = 'cadre = :cadre';
            $params[':cadre'] = $filters['cadre'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->pdo->prepare(
            "SELECT * FROM participants {$whereSql} ORDER BY name ASC LIMIT :limit OFFSET :offset"
        );

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function count(array $filters = []): int
    {
        $where  = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]           = '(name ILIKE :search OR reg_no ILIKE :search OR email ILIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['cadre'])) {
            $where[]          = 'cadre = :cadre';
            $params[':cadre'] = $filters['cadre'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM participants {$whereSql}");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}
