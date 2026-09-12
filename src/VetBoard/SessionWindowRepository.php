<?php

declare(strict_types=1);

namespace VetBoard;

class SessionWindowRepository extends LookupRepository
{
    protected function tableName(): string
    {
        return 'session_windows';
    }

    public function create(array $data): int
    {
        $key       = trim($data['item_key'] ?? '');
        $label     = trim($data['label'] ?? '');
        $startTime = $data['start_time'] ?? '';
        $endTime   = $data['end_time'] ?? '';

        if ($key === '' || $label === '' || $startTime === '' || $endTime === '') {
            throw new \InvalidArgumentException('Key, label, start time, and end time are required.');
        }

        if ($endTime <= $startTime) {
            throw new \InvalidArgumentException('End time must be after start time.');
        }

        if ($this->findByKey($key)) {
            throw new \InvalidArgumentException('An item with this key already exists.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO session_windows (item_key, label, start_time, end_time, sort_order)
             VALUES (:key, :label, :start_time, :end_time, :sort_order)
             RETURNING id'
        );
        $stmt->execute([
            ':key'        => $key,
            ':label'      => $label,
            ':start_time' => $startTime,
            ':end_time'   => $endTime,
            ':sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function update(int $id, array $data): bool
    {
        $label     = trim($data['label'] ?? '');
        $startTime = $data['start_time'] ?? '';
        $endTime   = $data['end_time'] ?? '';

        if ($label === '' || $startTime === '' || $endTime === '') {
            throw new \InvalidArgumentException('Label, start time, and end time are required.');
        }

        if ($endTime <= $startTime) {
            throw new \InvalidArgumentException('End time must be after start time.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE session_windows
             SET label = :label, start_time = :start_time, end_time = :end_time,
                 sort_order = :sort_order, updated_at = now()
             WHERE id = :id'
        );

        return $stmt->execute([
            ':id'         => $id,
            ':label'      => $label,
            ':start_time' => $startTime,
            ':end_time'   => $endTime,
            ':sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);
    }
}