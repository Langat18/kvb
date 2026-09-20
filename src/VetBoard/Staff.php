<?php

declare(strict_types=1);

namespace VetBoard;

use PDO;

class Staff
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getPdo();
    }

    public function create(array $data): int
    {
        if (empty($data['name'])) {
            throw new \InvalidArgumentException('Name is required.');
        }

        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid email is required.');
        }

        if (empty($data['password']) || strlen($data['password']) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters.');
        }

        if ($this->findByEmail($data['email'])) {
            throw new \InvalidArgumentException('An account with this email already exists.');
        }

        $roleId = (int) ($data['role_id'] ?? 0);

        if ($roleId <= 0 || !(new Role())->findById($roleId)) {
            throw new \InvalidArgumentException('A valid role is required.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO staff (name, email, password_hash, role_id)
             VALUES (:name, :email, :password_hash, :role_id)
             RETURNING id'
        );

        $stmt->execute([
            ':name'          => trim($data['name']),
            ':email'         => strtolower(trim($data['email'])),
            ':password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            ':role_id'       => $roleId,
        ]);

        return (int) $stmt->fetchColumn();
    }

    // Creates a staff account with no usable password 
    public function createWithInvite(string $name, string $email, int $roleId): array
    {
        if (empty($name)) {
            throw new \InvalidArgumentException('Name is required.');
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid email is required.');
        }

        if ($this->findByEmail($email)) {
            throw new \InvalidArgumentException('An account with this email already exists.');
        }

        if ($roleId <= 0 || !(new Role())->findById($roleId)) {
            throw new \InvalidArgumentException('A valid role is required.');
        }

        $unusablePassword = bin2hex(random_bytes(32));

        $stmt = $this->pdo->prepare(
            'INSERT INTO staff (name, email, password_hash, role_id)
             VALUES (:name, :email, :password_hash, :role_id)
             RETURNING id'
        );
        $stmt->execute([
            ':name'          => trim($name),
            ':email'         => strtolower(trim($email)),
            ':password_hash' => password_hash($unusablePassword, PASSWORD_DEFAULT),
            ':role_id'       => $roleId,
        ]);

        $staffId  = (int) $stmt->fetchColumn();
        $rawToken = $this->createPasswordResetToken($email);

        return ['id' => $staffId, 'token' => $rawToken];
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, r.item_key AS role, r.label AS role_label
             FROM staff s
             JOIN roles r ON r.id = s.role_id
             WHERE s.email = :email'
        );
        $stmt->execute([':email' => strtolower(trim($email))]);

        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function verifyPassword(string $email, string $password): ?array
    {
        $staff = $this->findByEmail($email);

        if (!$staff || !$staff['is_active'] || !password_verify($password, $staff['password_hash'])) {
            return null;
        }

        unset($staff['password_hash']);

        return $staff;
    }

    public function list(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]           = '(s.name ILIKE :search OR s.email ILIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->pdo->prepare(
            "SELECT s.id, s.name, s.email, s.role_id, r.item_key AS role, r.label AS role_label,
                    s.is_active, s.last_login_at, s.created_at
             FROM staff s
             JOIN roles r ON r.id = s.role_id
             {$whereSql}
             ORDER BY s.name ASC
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

    public function count(array $filters = []): int
    {
        $where  = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]           = '(s.name ILIKE :search OR s.email ILIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM staff s {$whereSql}");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.id, s.name, s.email, s.role_id, r.item_key AS role, r.label AS role_label,
                    s.is_active, s.last_login_at
             FROM staff s
             JOIN roles r ON r.id = s.role_id
             WHERE s.id = :id'
        );
        $stmt->execute([':id' => $id]);

        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function updateRole(int $id, int $roleId): bool
    {
        if (!(new Role())->findById($roleId)) {
            throw new \InvalidArgumentException('Invalid role.');
        }

        $stmt = $this->pdo->prepare('UPDATE staff SET role_id = :role_id WHERE id = :id');

        return $stmt->execute([':id' => $id, ':role_id' => $roleId]);
    }

    public function setActive(int $id, bool $active): bool
    {
        $stmt = $this->pdo->prepare('UPDATE staff SET is_active = :active WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':active', $active, PDO::PARAM_BOOL);

        return $stmt->execute();
    }

    public function resetPassword(int $id, string $newPassword): bool
    {
        if (strlen($newPassword) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters.');
        }

        $stmt = $this->pdo->prepare('UPDATE staff SET password_hash = :hash WHERE id = :id');

        return $stmt->execute([
            ':id'   => $id,
            ':hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        ]);
    }

    // --- Self-service password reset / account activation ---

    // Creates a single-use, 60-minute reset token for the given email.
    // Returns null if the email doesn't match an account, or if one was
    // already issued in the last 5 minutes (basic abuse throttle). The
    // calling page should show the same generic message either way, to
    // avoid leaking which emails are registered.
    public function createPasswordResetToken(string $email): ?string
    {
        $staff = $this->findByEmail($email);

        if (!$staff) {
            return null;
        }

        $recentStmt = $this->pdo->prepare(
            'SELECT EXISTS(
                SELECT 1 FROM password_reset_tokens
                WHERE staff_id = :staff_id AND created_at > now() - interval \'5 minutes\'
             )'
        );
        $recentStmt->execute([':staff_id' => $staff['id']]);

        if ((bool) $recentStmt->fetchColumn()) {
            return null;
        }

        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        $insert = $this->pdo->prepare(
            'INSERT INTO password_reset_tokens (staff_id, token_hash, expires_at)
             VALUES (:staff_id, :token_hash, now() + interval \'60 minutes\')'
        );
        $insert->execute([
            ':staff_id'   => $staff['id'],
            ':token_hash' => $tokenHash,
        ]);

        return $rawToken;
    }

    // Validates a raw token from the reset/activation link. Returns the
    // staff row if valid, unexpired, and unused — null otherwise.
    public function findByResetToken(string $rawToken): ?array
    {
        $tokenHash = hash('sha256', $rawToken);

        $stmt = $this->pdo->prepare(
            'SELECT s.id, s.name, s.email, prt.id AS token_id
             FROM password_reset_tokens prt
             JOIN staff s ON s.id = prt.staff_id
             WHERE prt.token_hash = :hash
               AND prt.used_at IS NULL
               AND prt.expires_at > now()'
        );
        $stmt->execute([':hash' => $tokenHash]);

        $result = $stmt->fetch();

        return $result ?: null;
    }

    // Resets the password and marks the token used in one transaction, so
    // a token can never be reused even if resetPassword() partially fails.
    public function resetPasswordWithToken(int $tokenId, int $staffId, string $newPassword): bool
    {
        $this->pdo->beginTransaction();

        try {
            $this->resetPassword($staffId, $newPassword);

            $stmt = $this->pdo->prepare('UPDATE password_reset_tokens SET used_at = now() WHERE id = :id');
            $stmt->execute([':id' => $tokenId]);

            $invalidate = $this->pdo->prepare(
                'UPDATE password_reset_tokens SET used_at = now() WHERE staff_id = :staff_id AND used_at IS NULL'
            );
            $invalidate->execute([':staff_id' => $staffId]);

            $this->pdo->commit();

            return true;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
