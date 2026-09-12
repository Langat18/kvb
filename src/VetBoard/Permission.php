<?php

declare(strict_types=1);

namespace VetBoard;

use PDO;

class Permission
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getPdo();
    }

    public function list(): array
    {
        return $this->pdo->query('SELECT * FROM permissions ORDER BY label ASC')->fetchAll();
    }
}
