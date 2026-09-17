<?php

declare(strict_types=1);

namespace VetBoard;

use PDO;

abstract class LookupRepository
{
    private static array $cache = [];

    protected PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getPdo();
    }

    abstract protected function tableName(): string;

    public function list(bool $activeOnly = false): array
    {
        $cacheKey = static::class . ($activeOnly ? ':active' : ':all');

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $table = $this->tableName();
        $sql   = "SELECT * FROM {$table}";

        if ($activeOnly) {
            $sql .= ' WHERE active = TRUE';
        }

        $sql .= ' ORDER BY sort_order ASC, label ASC';

        $stmt = $this->pdo->query($sql);

        return self::$cache[$cacheKey] = $stmt->fetchAll();
    }

    public function labelMap(bool $activeOnly = false): array
    {
        return array_column($this->list($activeOnly), 'label', 'item_key');
    }

    public function findById(int $id): ?array
    {
        $table = $this->tableName();
        $stmt  = $this->pdo->prepare("SELECT * FROM {$table} WHERE id = :id");
        $stmt->execute([':id' => $id]);

        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findByKey(string $key): ?array
    {
        $table = $this->tableName();
        $stmt  = $this->pdo->prepare("SELECT * FROM {$table} WHERE item_key = :key");
        $stmt->execute([':key' => trim($key)]);

        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function create(array $data): int
    {
        $key   = trim($data['item_key'] ?? '');
        $label = trim($data['label'] ?? '');

        if ($key === '' || $label === '') {
            throw new \InvalidArgumentException('Key and label are required.');
        }

        if ($this->findByKey($key)) {
            throw new \InvalidArgumentException('An item with this key already exists.');
        }

        $table = $this->tableName();
        $stmt  = $this->pdo->prepare(
            "INSERT INTO {$table} (item_key, label, sort_order) VALUES (:key, :label, :sort_order) RETURNING id"
        );
        $stmt->execute([
            ':key'        => $key,
            ':label'      => $label,
            ':sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        $this->clearCache();

        return (int) $stmt->fetchColumn();
    }

    public function update(int $id, array $data): bool
    {
        $label = trim($data['label'] ?? '');

        if ($label === '') {
            throw new \InvalidArgumentException('Label is required.');
        }

        $table = $this->tableName();
        $stmt  = $this->pdo->prepare(
            "UPDATE {$table} SET label = :label, sort_order = :sort_order, updated_at = now() WHERE id = :id"
        );

        $result = $stmt->execute([
            ':id'         => $id,
            ':label'      => $label,
            ':sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        $this->clearCache();

        return $result;
    }

    public function setActive(int $id, bool $active): bool
    {
        $table = $this->tableName();
        $stmt  = $this->pdo->prepare(
            "UPDATE {$table} SET active = :active, updated_at = now() WHERE id = :id"
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':active', $active, PDO::PARAM_BOOL);

        $result = $stmt->execute();

        $this->clearCache();

        return $result;
    }

    private function clearCache(): void
    {
        unset(self::$cache[static::class . ':active'], self::$cache[static::class . ':all']);
    }
}