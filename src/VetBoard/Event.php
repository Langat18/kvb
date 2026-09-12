<?php

declare(strict_types=1);

namespace VetBoard;

use PDO;

class Event
{
    private PDO $pdo;
    private EventModeRepository $eventModeRepo;
    private Department $departmentModel;

    public function __construct()
    {
        $this->pdo             = Database::getInstance()->getPdo();
        $this->eventModeRepo   = new EventModeRepository();
        $this->departmentModel = new Department();
    }

    public function create(array $data): int
    {
        $this->validate($data);

        $stmt = $this->pdo->prepare(
            'INSERT INTO events
                (title, event_code, event_mode_id, venue, department_id, coordinator, program_assistant,
                 start_date, end_date, geo_lat, geo_lng, geo_radius_m,
                 points_morning, points_mid_morning, points_afternoon, token, view_token, created_by)
             VALUES
                (:title, :event_code, :event_mode_id, :venue, :department_id, :coordinator, :program_assistant,
                 :start_date, :end_date, :geo_lat, :geo_lng, :geo_radius_m,
                 :points_morning, :points_mid_morning, :points_afternoon, :token, :view_token, :created_by)
             RETURNING id'
        );

        $stmt->execute([
            ':title'             => trim($data['title']),
            ':event_code'        => trim($data['event_code'] ?? ''),
            ':event_mode_id'     => (int) $data['event_mode_id'],
            ':venue'             => trim($data['venue'] ?? ''),
            ':department_id'     => (int) $data['department_id'],
            ':coordinator'       => trim($data['coordinator'] ?? ''),
            ':program_assistant' => trim($data['program_assistant'] ?? ''),
            ':start_date'        => $data['start_date'],
            ':end_date'          => $data['end_date'],
            ':geo_lat'           => $data['geo_lat']      ?? null,
            ':geo_lng'           => $data['geo_lng']      ?? null,
            ':geo_radius_m'      => $data['geo_radius_m'] ?? null,
            ':points_morning'     => $data['points_morning']     ?? null,
            ':points_mid_morning' => $data['points_mid_morning'] ?? null,
            ':points_afternoon'   => $data['points_afternoon']   ?? null,
            ':token'             => bin2hex(random_bytes(16)),
            ':view_token'        => bin2hex(random_bytes(16)),
            ':created_by'        => $data['created_by'] ?? null,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function update(int $id, array $data): bool
    {
        $this->validate($data);

        $stmt = $this->pdo->prepare(
            'UPDATE events SET
                title = :title, event_code = :event_code, event_mode_id = :event_mode_id,
                venue = :venue, department_id = :department_id, coordinator = :coordinator,
                program_assistant = :program_assistant, start_date = :start_date, end_date = :end_date,
                geo_lat = :geo_lat, geo_lng = :geo_lng, geo_radius_m = :geo_radius_m,
                points_morning = :points_morning, points_mid_morning = :points_mid_morning, points_afternoon = :points_afternoon
             WHERE id = :id'
        );

        return $stmt->execute([
            ':id'                => $id,
            ':title'             => trim($data['title']),
            ':event_code'        => trim($data['event_code'] ?? ''),
            ':event_mode_id'     => (int) $data['event_mode_id'],
            ':venue'             => trim($data['venue'] ?? ''),
            ':department_id'     => (int) $data['department_id'],
            ':coordinator'       => trim($data['coordinator'] ?? ''),
            ':program_assistant' => trim($data['program_assistant'] ?? ''),
            ':start_date'        => $data['start_date'],
            ':end_date'          => $data['end_date'],
            ':geo_lat'           => $data['geo_lat']      ?? null,
            ':geo_lng'           => $data['geo_lng']      ?? null,
            ':geo_radius_m'      => $data['geo_radius_m'] ?? null,
            ':points_morning'     => $data['points_morning']     ?? null,
            ':points_mid_morning' => $data['points_mid_morning'] ?? null,
            ':points_afternoon'   => $data['points_afternoon']   ?? null,
        ]);
    }

    private function validate(array $data): void
    {
        if (empty($data['title'])) {
            throw new \InvalidArgumentException('Event title is required.');
        }

        $mode = !empty($data['event_mode_id']) ? $this->eventModeRepo->findById((int) $data['event_mode_id']) : null;

        if (!$mode) {
            throw new \InvalidArgumentException('A valid event mode is required.');
        }

        if (empty($data['department_id']) || !$this->departmentModel->findById((int) $data['department_id'])) {
            throw new \InvalidArgumentException('A valid department is required.');
        }

        if (empty($data['start_date']) || empty($data['end_date'])) {
            throw new \InvalidArgumentException('Start and end dates are required.');
        }

        if ($data['end_date'] < $data['start_date']) {
            throw new \InvalidArgumentException('End date cannot be before start date.');
        }

        if ($mode['item_key'] !== 'virtual') {
            if (empty($data['geo_lat']) || empty($data['geo_lng']) || empty($data['geo_radius_m'])) {
                throw new \InvalidArgumentException('Location and radius are required for on-site or hybrid events.');
            }
        }

        $pointsTotal = (int) ($data['points_morning'] ?? 0)
            + (int) ($data['points_mid_morning'] ?? 0)
            + (int) ($data['points_afternoon'] ?? 0);

        if ($pointsTotal > 8) {
            throw new \InvalidArgumentException('Total points across all sessions cannot exceed 8 per day.');
        }

        foreach (['points_morning', 'points_mid_morning', 'points_afternoon'] as $field) {
            if (isset($data[$field]) && $data[$field] !== null && (int) $data[$field] < 0) {
                throw new \InvalidArgumentException('Points cannot be negative.');
            }
        }
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.*, d.name AS department_name, m.item_key AS event_mode, m.label AS event_mode_label
             FROM events e
             LEFT JOIN departments d ON d.id = e.department_id
             LEFT JOIN event_modes m ON m.id = e.event_mode_id
             WHERE e.id = :id'
        );
        $stmt->execute([':id' => $id]);

        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.*, d.name AS department_name, m.item_key AS event_mode, m.label AS event_mode_label
             FROM events e
             LEFT JOIN departments d ON d.id = e.department_id
             LEFT JOIN event_modes m ON m.id = e.event_mode_id
             WHERE e.token = :token'
        );
        $stmt->execute([':token' => trim($token)]);

        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findByViewToken(string $viewToken): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.*, d.name AS department_name, m.item_key AS event_mode, m.label AS event_mode_label
             FROM events e
             LEFT JOIN departments d ON d.id = e.department_id
             LEFT JOIN event_modes m ON m.id = e.event_mode_id
             WHERE e.view_token = :view_token'
        );
        $stmt->execute([':view_token' => trim($viewToken)]);

        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function list(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]           = 'e.title ILIKE :search';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['event_mode'])) {
            $where[]               = 'm.item_key = :event_mode';
            $params[':event_mode'] = $filters['event_mode'];
        }

        if (!empty($filters['date'])) {
            $where[]          = ':date BETWEEN e.start_date AND e.end_date';
            $params[':date']  = $filters['date'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->pdo->prepare(
            "SELECT e.*, d.name AS department_name, m.item_key AS event_mode, m.label AS event_mode_label
             FROM events e
             LEFT JOIN departments d ON d.id = e.department_id
             LEFT JOIN event_modes m ON m.id = e.event_mode_id
             {$whereSql}
             ORDER BY e.start_date DESC LIMIT :limit OFFSET :offset"
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
            $where[]           = 'e.title ILIKE :search';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['event_mode'])) {
            $where[]               = 'm.item_key = :event_mode';
            $params[':event_mode'] = $filters['event_mode'];
        }

        if (!empty($filters['date'])) {
            $where[]          = ':date BETWEEN e.start_date AND e.end_date';
            $params[':date']  = $filters['date'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM events e LEFT JOIN event_modes m ON m.id = e.event_mode_id {$whereSql}"
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function getParticipants(int $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id AS registration_id, p.reg_no, p.name, p.cadre, p.email, p.phone, r.registered_at
             FROM registrations r
             JOIN participants p ON p.reg_no = r.reg_no
             WHERE r.event_id = :event_id
             ORDER BY p.name ASC'
        );
        $stmt->execute([':event_id' => $eventId]);
        $participants = $stmt->fetchAll();

        foreach ($participants as &$participant) {
            $attStmt = $this->pdo->prepare(
                'SELECT session, attendance_date, method, flagged, distance_m, flag_reason
                 FROM attendance
                 WHERE registration_id = :registration_id
                 ORDER BY attendance_date ASC, session ASC'
            );
            $attStmt->execute([':registration_id' => $participant['registration_id']]);
            $participant['attendance'] = $attStmt->fetchAll();

            $ptsStmt = $this->pdo->prepare(
                'SELECT COALESCE(SUM(points), 0) FROM points_ledger WHERE event_id = :event_id AND reg_no = :reg_no'
            );
            $ptsStmt->execute([':event_id' => $eventId, ':reg_no' => $participant['reg_no']]);
            $participant['points_earned'] = (int) $ptsStmt->fetchColumn();
        }
        unset($participant);

        return $participants;
    }

    public function isWithinGeofence(array $event, float $lat, float $lng): array
    {
        if (empty($event['geo_lat']) || empty($event['geo_lng']) || empty($event['geo_radius_m'])) {
            return ['within' => false, 'distance_m' => null];
        }

        $distance = $this->haversineDistance(
            (float) $event['geo_lat'],
            (float) $event['geo_lng'],
            $lat,
            $lng
        );

        return [
            'within'     => $distance <= (float) $event['geo_radius_m'],
            'distance_m' => (int) round($distance),
        ];
    }

    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusM = 6371000;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusM * $c;
    }
}