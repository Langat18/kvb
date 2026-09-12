<?php

declare(strict_types=1);

namespace VetBoard;

use PDO;

class Role
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getPdo();
    }

    public function list(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM roles';

        if ($activeOnly) {
            $sql .= ' WHERE active = TRUE';
        }

        $sql .= ' ORDER BY sort_order ASC, label ASC';

        return $this->pdo->query($sql)->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM roles WHERE id = :id');
        $stmt->execute([':id' => $id]);

        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findByKey(string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM roles WHERE item_key = :key');
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
            throw new \InvalidArgumentException('A role with this key already exists.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO roles (item_key, label, sort_order) VALUES (:key, :label, :sort_order) RETURNING id'
        );
        $stmt->execute([
            ':key'        => $key,
            ':label'      => $label,
            ':sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function update(int $id, array $data): bool
    {
        $label = trim($data['label'] ?? '');

        if ($label === '') {
            throw new \InvalidArgumentException('Label is required.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE roles SET label = :label, sort_order = :sort_order, updated_at = now() WHERE id = :id'
        );

        return $stmt->execute([
            ':id'         => $id,
            ':label'      => $label,
            ':sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);
    }

    public function setActive(int $id, bool $active): bool
    {
        $role = $this->findById($id);

        if ($role && $role['is_system'] && !$active) {
            throw new \InvalidArgumentException('This role is required by the system and cannot be deactivated.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE roles SET active = :active, updated_at = now() WHERE id = :id'
        );

        return $stmt->execute([':id' => $id, ':active' => $active]);
    }

    public function getPermissionKeys(int $roleId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.item_key FROM permissions p
             JOIN role_permissions rp ON rp.permission_id = p.id
             WHERE rp.role_id = :role_id'
        );
        $stmt->execute([':role_id' => $roleId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function setPermissions(int $roleId, array $permissionIds): void
    {
        $this->pdo->beginTransaction();

        try {
            $del = $this->pdo->prepare('DELETE FROM role_permissions WHERE role_id = :role_id');
            $del->execute([':role_id' => $roleId]);

            $ins = $this->pdo->prepare(
                'INSERT INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)'
            );

            foreach ($permissionIds as $permissionId) {
                $ins->execute([':role_id' => $roleId, ':permission_id' => (int) $permissionId]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function roleHasPermission(string $roleKey, string $permissionKey): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM roles r
             JOIN role_permissions rp ON rp.role_id = r.id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE r.item_key = :role_key AND p.item_key = :permission_key AND r.active = TRUE
             LIMIT 1'
        );
        $stmt->execute([':role_key' => $roleKey, ':permission_key' => $permissionKey]);

        return (bool) $stmt->fetchColumn();
    }
}
