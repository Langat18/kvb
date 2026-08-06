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

        $stmt = $this->pdo->prepare(
            'INSERT INTO staff (name, email, password_hash, role)
             VALUES (:name, :email, :password_hash, :role)
             RETURNING id'
        );

        $stmt->execute([
            ':name'          => trim($data['name']),
            ':email'         => strtolower(trim($data['email'])),
            ':password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            ':role'          => $data['role'] ?? 'staff',
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM staff WHERE email = :email');
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

    public function list(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, name, email, role, is_active, last_login_at, created_at FROM staff ORDER BY name ASC'
        );

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, email, role, is_active, last_login_at FROM staff WHERE id = :id');
        $stmt->execute([':id' => $id]);

        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function updateRole(int $id, string $role): bool
    {
        if (!in_array($role, ['admin', 'staff', 'ceo'], true)) {
            throw new \InvalidArgumentException('Invalid role.');
        }

        $stmt = $this->pdo->prepare('UPDATE staff SET role = :role WHERE id = :id');

        return $stmt->execute([':id' => $id, ':role' => $role]);
    }

    public function setActive(int $id, bool $active): bool
    {
        $stmt = $this->pdo->prepare('UPDATE staff SET is_active = :active WHERE id = :id');

        return $stmt->execute([':id' => $id, ':active' => $active]);
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
}
