<?php

declare(strict_types=1);

namespace VetBoard;

use PDO;

class Provider
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getPdo();
    }

    public function create(array $data): int
    {
        $accreditationNo = trim($data['accreditation_no'] ?? '');
        $name            = trim($data['name'] ?? '');

        if ($accreditationNo === '' || $name === '') {
            throw new \InvalidArgumentException('Accreditation number and name are required.');
        }

        if ($this->findByAccreditationNo($accreditationNo)) {
            throw new \InvalidArgumentException('A provider with this accreditation number already exists.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO providers (accreditation_no, name) VALUES (:acc, :name) RETURNING id'
        );
        $stmt->execute([':acc' => $accreditationNo, ':name' => $name]);

        return (int) $stmt->fetchColumn();
    }

    public function findByAccreditationNo(string $accreditationNo): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM providers WHERE accreditation_no = :acc');
        $stmt->execute([':acc' => trim($accreditationNo)]);

        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM providers WHERE id = :id');
        $stmt->execute([':id' => $id]);

        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function list(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM providers';

        if ($activeOnly) {
            $sql .= ' WHERE active = TRUE';
        }

        $sql .= ' ORDER BY name ASC';

        return $this->pdo->query($sql)->fetchAll();
    }

    public function rename(int $id, string $name): bool
    {
        $name = trim($name);

        if ($name === '') {
            throw new \InvalidArgumentException('Name is required.');
        }

        $stmt = $this->pdo->prepare('UPDATE providers SET name = :name, updated_at = now() WHERE id = :id');

        return $stmt->execute([':id' => $id, ':name' => $name]);
    }

    public function setActive(int $id, bool $active): bool
    {
        $stmt = $this->pdo->prepare('UPDATE providers SET active = :active, updated_at = now() WHERE id = :id');

        return $stmt->execute([':id' => $id, ':active' => $active]);
    }

    /**
     * Imports providers from an uploaded CSV file. Expects two columns per
     * row: accreditation number, name (a header row is auto-detected and
     * skipped if its first cell doesn't look like an accreditation number).
     */
    public function importFromCsv(string $filePath): array
    {
        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new \RuntimeException('Could not read the uploaded file.');
        }

        $rowNum = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;

            if (count($row) < 2) {
                continue;
            }

            $accreditationNo = trim($row[0]);
            $name            = trim($row[1]);

            if ($rowNum === 1 && (stripos($accreditationNo, 'no') === 0 || stripos($accreditationNo, 'accreditation') !== false)) {
                continue;
            }

            if ($accreditationNo === '' || $name === '') {
                $errors[] = "Row {$rowNum}: missing accreditation number or name.";
                continue;
            }

            if ($this->findByAccreditationNo($accreditationNo)) {
                $skipped++;
                continue;
            }

            try {
                $this->create(['accreditation_no' => $accreditationNo, 'name' => $name]);
                $imported++;
            } catch (\InvalidArgumentException $e) {
                $errors[] = "Row {$rowNum}: " . $e->getMessage();
            }
        }

        fclose($handle);

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }
}
