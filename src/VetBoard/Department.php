<?php

declare(strict_types=1);

namespace VetBoard;

use PDO;

class Department
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getPdo();
    }

    public function create(array $data): int
    {
        $name = trim($data['name'] ?? '');

        if ($name === '') {
            throw new \InvalidArgumentException('Department name is required.');
        }

        if ($this->findByName($name)) {
            throw new \InvalidArgumentException('A department with this name already exists.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO departments (name) VALUES (:name) RETURNING id'
        );
        $stmt->execute([':name' => $name]);

        return (int) $stmt->fetchColumn();
    }

    public function findByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM departments WHERE name = :name');
        $stmt->execute([':name' => trim($name)]);

        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM departments WHERE id = :id');
        $stmt->execute([':id' => $id]);

        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function list(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM departments';

        if ($activeOnly) {
            $sql .= ' WHERE active = TRUE';
        }

        $sql .= ' ORDER BY name ASC';

        $stmt = $this->pdo->query($sql);

        return $stmt->fetchAll();
    }

    public function rename(int $id, string $newName): bool
    {
        $newName = trim($newName);

        if ($newName === '') {
            throw new \InvalidArgumentException('Department name is required.');
        }

        $existing = $this->findByName($newName);

        if ($existing && (int) $existing['id'] !== $id) {
            throw new \InvalidArgumentException('A department with this name already exists.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE departments SET name = :name, updated_at = now() WHERE id = :id'
        );

        return $stmt->execute([':id' => $id, ':name' => $newName]);
    }

    public function setActive(int $id, bool $active): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE departments SET active = :active, updated_at = now() WHERE id = :id'
        );

        return $stmt->execute([':id' => $id, ':active' => $active]);
    }
}