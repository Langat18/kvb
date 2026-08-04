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

    public static function isValidRegNo(string $regNo): bool
    {
        return (bool) preg_match('/^(\d{4}|VT[ABC]\d{4})$/', $regNo);
    }

    public function create(array $data): string
    {
        $regNo = strtoupper(trim($data['reg_no'] ?? ''));

        if (!self::isValidRegNo($regNo)) {
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
