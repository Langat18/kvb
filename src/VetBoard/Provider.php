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
        $contactPerson   = trim($data['contact_person'] ?? '');
        $phone           = trim($data['phone'] ?? '');
        $email           = trim($data['email'] ?? '');

        if ($accreditationNo === '' || $name === '') {
            throw new \InvalidArgumentException('Accreditation number and name are required.');
        }

        if ($this->findByAccreditationNo($accreditationNo)) {
            throw new \InvalidArgumentException('A provider with this accreditation number already exists.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO providers (accreditation_no, name, contact_person, phone, email)
             VALUES (:acc, :name, :contact_person, :phone, :email) RETURNING id'
        );
        $stmt->execute([
            ':acc'            => $accreditationNo,
            ':name'           => $name,
            ':contact_person' => $contactPerson !== '' ? $contactPerson : null,
            ':phone'          => $phone !== '' ? $phone : null,
            ':email'          => $email !== '' ? $email : null,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function update(int $id, array $data): bool
    {
        $name          = trim($data['name'] ?? '');
        $contactPerson = trim($data['contact_person'] ?? '');
        $phone         = trim($data['phone'] ?? '');
        $email         = trim($data['email'] ?? '');

        if ($name === '') {
            throw new \InvalidArgumentException('Name is required.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE providers SET name = :name, contact_person = :contact_person, phone = :phone, email = :email, updated_at = now()
             WHERE id = :id'
        );

        return $stmt->execute([
            ':id'             => $id,
            ':name'           => $name,
            ':contact_person' => $contactPerson !== '' ? $contactPerson : null,
            ':phone'          => $phone !== '' ? $phone : null,
            ':email'          => $email !== '' ? $email : null,
        ]);
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

    /**
     * Paginated, searchable listing for the providers admin page.
     * Separate from list() so existing callers of list() (dropdowns,
     * lookups elsewhere in the app) keep getting the full unpaginated
     * result set they already rely on.
     */
    public function paginatedList(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]           = '(name ILIKE :search OR accreditation_no ILIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->pdo->prepare(
            "SELECT * FROM providers {$whereSql} ORDER BY name ASC LIMIT :limit OFFSET :offset"
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
            $where[]           = '(name ILIKE :search OR accreditation_no ILIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM providers {$whereSql}");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
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
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':active', $active, PDO::PARAM_BOOL);

        return $stmt->execute();
    }

    /**
     * Imports providers from an uploaded CSV file. Expects up to five
     * columns per row, in order: No (accreditation number), Name,
     * Contact Person, Phone No, Email. Only the first two are required.
     * A header row is auto-detected and skipped if the first cell
     * doesn't look like an accreditation number.
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
        while (($row = fgetcsv($handle, 0, ',', '"', '\\\\')) !== false) {
            $rowNum++;

            if (count($row) < 2) {
                continue;
            }

            $accreditationNo = trim($row[0]);
            $name            = trim($row[1]);
            $contactPerson   = trim($row[2] ?? '');
            $phone           = trim($row[3] ?? '');
            $email           = trim($row[4] ?? '');

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
                $this->create([
                    'accreditation_no' => $accreditationNo,
                    'name'             => $name,
                    'contact_person'   => $contactPerson,
                    'phone'            => $phone,
                    'email'            => $email,
                ]);
                $imported++;
            } catch (\InvalidArgumentException $e) {
                $errors[] = "Row {$rowNum}: " . $e->getMessage();
            }
        }

        fclose($handle);

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }
}
