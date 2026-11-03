<?php

declare(strict_types=1);

namespace VetBoard;

use PDO;

class Event
{
    private PDO $pdo;
    private EventModeRepository $eventModeRepo;

    public function __construct()
    {
        $this->pdo           = Database::getInstance()->getPdo();
        $this->eventModeRepo = new EventModeRepository();
    }

    public function create(array $data): array
    {
        $this->validate($data);

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO events
                    (title, event_code, event_mode_id, venue, provider_id, coordinator,
                     start_date, end_date, geo_lat, geo_lng, geo_radius_m,
                     meeting_provider, meeting_link, token, view_token, created_by)
                 VALUES
                    (:title, :event_code, :event_mode_id, :venue, :provider_id, :coordinator,
                     :start_date, :end_date, :geo_lat, :geo_lng, :geo_radius_m,
                     :meeting_provider, :meeting_link, :token, :view_token, :created_by)
                 RETURNING id'
            );

            $stmt->execute([
                ':title'            => trim($data['title']),
                ':event_code'       => trim($data['event_code'] ?? ''),
                ':event_mode_id'    => (int) $data['event_mode_id'],
                ':venue'            => trim($data['venue'] ?? ''),
                ':provider_id'      => !empty($data['provider_id']) ? (int) $data['provider_id'] : null,
                ':coordinator'      => trim($data['coordinator'] ?? ''),
                ':start_date'       => $data['start_date'],
                ':end_date'         => $data['end_date'],
                ':geo_lat'          => $data['geo_lat']      ?? null,
                ':geo_lng'          => $data['geo_lng']      ?? null,
                ':geo_radius_m'     => $data['geo_radius_m'] ?? null,
                ':meeting_provider' => !empty($data['meeting_provider']) ? $data['meeting_provider'] : null,
                ':meeting_link'     => !empty($data['meeting_link']) ? trim($data['meeting_link']) : null,
                ':token'            => bin2hex(random_bytes(16)),
                ':view_token'       => bin2hex(random_bytes(16)),
                ':created_by'       => $data['created_by'] ?? null,
            ]);

            $eventId = (int) $stmt->fetchColumn();

            $sessionIdMap = $this->saveSessions($eventId, $data['sessions'] ?? []);
            (new SessionPresentation($this->pdo))->save($data['presentations'] ?? [], $sessionIdMap, $data['sessions'] ?? []);
            (new PresenterBonus($this->pdo))->reconcile($eventId);

            $this->pdo->commit();

            return ['id' => $eventId, 'session_id_map' => $sessionIdMap];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): array
    {
        $this->validate($data);

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'UPDATE events SET
                    title = :title, event_code = :event_code, event_mode_id = :event_mode_id,
                    venue = :venue, provider_id = :provider_id,
                    coordinator = :coordinator, start_date = :start_date, end_date = :end_date,
                    geo_lat = :geo_lat, geo_lng = :geo_lng, geo_radius_m = :geo_radius_m,
                    meeting_provider = :meeting_provider, meeting_link = :meeting_link
                 WHERE id = :id'
            );

            $result = $stmt->execute([
                ':id'               => $id,
                ':title'            => trim($data['title']),
                ':event_code'       => trim($data['event_code'] ?? ''),
                ':event_mode_id'    => (int) $data['event_mode_id'],
                ':venue'            => trim($data['venue'] ?? ''),
                ':provider_id'      => !empty($data['provider_id']) ? (int) $data['provider_id'] : null,
                ':coordinator'      => trim($data['coordinator'] ?? ''),
                ':start_date'       => $data['start_date'],
                ':end_date'         => $data['end_date'],
                ':geo_lat'          => $data['geo_lat']      ?? null,
                ':geo_lng'          => $data['geo_lng']      ?? null,
                ':geo_radius_m'     => $data['geo_radius_m'] ?? null,
                ':meeting_provider' => !empty($data['meeting_provider']) ? $data['meeting_provider'] : null,
                ':meeting_link'     => !empty($data['meeting_link']) ? trim($data['meeting_link']) : null,
            ]);

            $sessionIdMap = $this->saveSessions($id, $data['sessions'] ?? []);
            (new SessionPresentation($this->pdo))->save($data['presentations'] ?? [], $sessionIdMap, $data['sessions'] ?? []);
            (new PresenterBonus($this->pdo))->reconcile($id);

            $this->pdo->commit();

            return ['result' => $result, 'session_id_map' => $sessionIdMap];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function saveSessions(int $eventId, array $sessions): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM event_sessions WHERE event_id = :event_id AND active = TRUE');
        $stmt->execute([':event_id' => $eventId]);
        $currentIds = array_column($stmt->fetchAll(), 'id');

        $idMap   = [];
        $keptIds = [];

        foreach ($sessions as $clientId => $s) {
            $label     = trim($s['label'] ?? '');
            $startTime = $s['start_time'] ?? '';
            $endTime   = $s['end_time'] ?? '';
            $points    = (int) ($s['points'] ?? 0);
            $sortOrder = (int) ($s['sort_order'] ?? 0);
            $sessionDate = $this->normalizeSessionDate($s['session_date'] ?? '');
            $requireLateSignin = !empty($s['require_late_signin']) ? 'true' : 'false';

            if ($label === '' || $startTime === '' || $endTime === '') {
                continue;
            }

            $signinLead  = $this->optionalMinutes($s['signin_lead_minutes'] ?? null, 1, 180, $label);
            $signinGrace = $this->optionalMinutes($s['signin_grace_minutes'] ?? null, 0, 180, $label);

            if (is_numeric($clientId)) {
                $id = (int) $clientId;

                $upd = $this->pdo->prepare(
                    'UPDATE event_sessions
                     SET label = :label, start_time = :start_time, end_time = :end_time,
                         points = :points, sort_order = :sort_order, session_date = :session_date, require_late_signin = :require_late_signin,
                         signin_lead_minutes = :signin_lead, signin_grace_minutes = :signin_grace, updated_at = now()
                     WHERE id = :id AND event_id = :event_id'
                );
                $upd->execute([
                    ':signin_lead'  => $signinLead,
                    ':signin_grace' => $signinGrace,
                    ':session_date' => $sessionDate,
                    ':require_late_signin' => $requireLateSignin,
                    ':label'      => $label,
                    ':start_time' => $startTime,
                    ':end_time'   => $endTime,
                    ':points'     => $points,
                    ':sort_order' => $sortOrder,
                    ':id'         => $id,
                    ':event_id'   => $eventId,
                ]);

                $idMap[$clientId] = $id;
                $keptIds[]        = $id;
            } else {
                $ins = $this->pdo->prepare(
                    'INSERT INTO event_sessions (event_id, label, start_time, end_time, points, sort_order, session_date, require_late_signin, signin_lead_minutes, signin_grace_minutes)
                     VALUES (:event_id, :label, :start_time, :end_time, :points, :sort_order, :session_date, :require_late_signin, :signin_lead, :signin_grace)
                     RETURNING id'
                );
                $ins->execute([
                    ':signin_lead'  => $signinLead,
                    ':signin_grace' => $signinGrace,
                    ':session_date' => $sessionDate,
                    ':require_late_signin' => $requireLateSignin,
                    ':event_id'   => $eventId,
                    ':label'      => $label,
                    ':start_time' => $startTime,
                    ':end_time'   => $endTime,
                    ':points'     => $points,
                    ':sort_order' => $sortOrder,
                ]);

                $newId            = (int) $ins->fetchColumn();
                $idMap[$clientId] = $newId;
                $keptIds[]        = $newId;
            }
        }

        $removedIds = array_diff($currentIds, $keptIds);

        if (!empty($removedIds)) {
            $placeholders = implode(',', array_fill(0, count($removedIds), '?'));
            $deact = $this->pdo->prepare(
                "UPDATE event_sessions SET active = FALSE, updated_at = now() WHERE id IN ({$placeholders})"
            );
            $deact->execute(array_values($removedIds));
        }

        return $idMap;
    }

    private function normalizeSessionDate($value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $parsed = \DateTime::createFromFormat('Y-m-d', $value);

        return ($parsed && $parsed->format('Y-m-d') === $value) ? $value : null;
    }

    public function signInWhenText(int $eventId): string
    {
        $leads   = [];
        $anytime = false;

        foreach ($this->getSessions($eventId) as $s) {
            if ($s['require_late_signin'] ?? true) {
                $leads[] = SigninRules::lead($s, 'virtual');
            } else {
                $anytime = true;
            }
        }

        if ($leads === []) {
            return $anytime
                ? 'at any time while a session is running'
                : 'during the final ' . SigninRules::get('signin_lead_virtual') . ' minutes of each session';
        }

        $min   = min($leads);
        $max   = max($leads);
        $range = $min === $max ? "{$min} minutes" : "{$min} to {$max} minutes";

        return $anytime
            ? "during each session (some sessions only open sign-in in their final {$range})"
            : "during the final {$range} of each session";
    }

    public static function pickSignInSession(array $sessions, int $nowTs, int $graceMinutes): array
    {
        $none    = ['session_id' => null, 'window' => null, 'late' => false, 'closes_at' => null];
        $today   = date('Y-m-d', $nowTs);
        $nowTime = date('H:i:s', $nowTs);
        $dayEnd  = strtotime($today . ' 23:59:59');

        $todays = [];

        foreach ($sessions as $w) {
            if (!empty($w['session_date']) && $w['session_date'] !== $today) {
                continue;
            }

            $todays[] = $w;
        }

        foreach ($todays as $w) {
            if ($nowTime >= $w['start_time'] && $nowTime <= $w['end_time']) {
                return ['session_id' => (int) $w['id'], 'window' => $w, 'late' => false, 'closes_at' => null];
            }
        }

        $best      = null;
        $bestEndTs = 0;
        $bestClose = 0;

        foreach ($todays as $w) {
            $endTs = strtotime($today . ' ' . $w['end_time']);

            if ($nowTs <= $endTs) {
                continue;
            }

            $nextStart = $dayEnd;

            foreach ($todays as $o) {
                $oStart = strtotime($today . ' ' . $o['start_time']);

                if ($o['start_time'] > $w['start_time'] && $oStart < $nextStart) {
                    $nextStart = $oStart;
                }
            }

            $graceSeconds = ((int) ($w['signin_grace_minutes'] ?? $graceMinutes)) * 60;
            $closesAt     = max($endTs, min($endTs + $graceSeconds, $nextStart));

            if ($nowTs >= $closesAt) {
                continue;
            }

            if ($best === null || $endTs > $bestEndTs) {
                $best      = $w;
                $bestEndTs = $endTs;
                $bestClose = $closesAt;
            }
        }

        if ($best === null) {
            return $none;
        }

        return ['session_id' => (int) $best['id'], 'window' => $best, 'late' => true, 'closes_at' => $bestClose];
    }

    public function resolveSignInSession(int $eventId): array
    {
        $grace = SigninRules::get('signin_grace');

        return self::pickSignInSession($this->getSessions($eventId), time(), $grace);
    }

    private function optionalMinutes(mixed $value, int $min, int $max, string $label): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $n = filter_var($value, FILTER_VALIDATE_INT);

        if ($n === false || $n < $min || $n > $max) {
            throw new \InvalidArgumentException("Session \"{$label}\": sign-in minutes must be between {$min} and {$max}.");
        }

        return $n;
    }

    public function getSessions(int $eventId, bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM event_sessions WHERE event_id = :event_id';

        if ($activeOnly) {
            $sql .= ' AND active = TRUE';
        }

        $sql .= ' ORDER BY sort_order ASC, id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':event_id' => $eventId]);

        return $stmt->fetchAll();
    }

    // Finds a recently-created event with a matching title, so create.php
    // can warn instead of silently allowing a second copy of a mistyped
    // or resubmitted event. Not a hard uniqueness constraint — recurring
    // events legitimately reuse titles — so this is a lookup, not a guard.
    public function findRecentSimilarTitle(string $title, int $withinHours = 24, ?int $excludeId = null): ?array
    {
        $sql = "SELECT id, title, event_code, start_date, end_date, created_at
                FROM events
                WHERE lower(trim(title)) = lower(trim(:title))
                  AND created_at >= now() - (:hours || ' hours')::interval";

        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
        }

        $sql .= ' ORDER BY created_at DESC LIMIT 1';

        $stmt   = $this->pdo->prepare($sql);
        $params = [':title' => $title, ':hours' => $withinHours];

        if ($excludeId !== null) {
            $params[':exclude_id'] = $excludeId;
        }

        $stmt->execute($params);
        $result = $stmt->fetch();

        return $result ?: null;
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

        if (!empty($data['provider_id'])) {
            $providerModel = new Provider();
            if (!$providerModel->findById((int) $data['provider_id'])) {
                throw new \InvalidArgumentException('The selected provider could not be found. Please re-select it using the accreditation number lookup.');
            }
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

        // Any event with a virtual component needs a place for virtual attendees to go.
        if ($mode['item_key'] !== 'onsite') {
            $link = trim($data['meeting_link'] ?? '');

            if ($link === '') {
                throw new \InvalidArgumentException('A meeting link is required for virtual or hybrid events.');
            }

            if (!preg_match('#^https?://#i', $link)) {
                throw new \InvalidArgumentException('The meeting link must be a valid URL starting with http:// or https://.');
            }
        }

        $sessions    = $data['sessions'] ?? [];
        $recurringTotal = 0;
        $datedTotals    = [];
        $validCount  = 0;

        foreach ($sessions as $s) {
            $label     = trim($s['label'] ?? '');
            $startTime = $s['start_time'] ?? '';
            $endTime   = $s['end_time'] ?? '';
            $points    = (int) ($s['points'] ?? 0);

            if ($label === '' || $startTime === '' || $endTime === '') {
                continue;
            }

            if ($endTime <= $startTime) {
                throw new \InvalidArgumentException("Session \"{$label}\": end time must be after start time.");
            }

            if ($points < 0) {
                throw new \InvalidArgumentException('Points cannot be negative.');
            }

            $rawDate = trim((string) ($s['session_date'] ?? ''));

            if ($rawDate !== '') {
                $parsedDate = \DateTime::createFromFormat('Y-m-d', $rawDate);

                if (!$parsedDate || $parsedDate->format('Y-m-d') !== $rawDate) {
                    throw new \InvalidArgumentException("Session \"{$label}\": the date is not valid.");
                }

                if ($rawDate < $data['start_date'] || $rawDate > $data['end_date']) {
                    throw new \InvalidArgumentException("Session \"{$label}\": the date must fall within the event dates.");
                }

                $datedTotals[$rawDate] = ($datedTotals[$rawDate] ?? 0) + $points;
            } else {
                $recurringTotal += $points;
            }
            $validCount++;
        }

        if ($validCount < 1) {
            throw new \InvalidArgumentException('At least one session is required.');
        }

        $pointsTotal = $recurringTotal + ($datedTotals ? max($datedTotals) : 0);

        if ($pointsTotal > 8) {
            throw new \InvalidArgumentException('Total points on any single day cannot exceed 8.');
        }
    }

    private function baseSelect(): string
    {
        return 'SELECT e.*, m.item_key AS event_mode, m.label AS event_mode_label,
                        pr.name AS provider_name, pr.accreditation_no AS provider_accreditation_no,
                        pr.contact_person AS provider_contact_person, pr.phone AS provider_phone,
                        pr.email AS provider_email
                 FROM events e
                 LEFT JOIN event_modes m ON m.id = e.event_mode_id
                 LEFT JOIN providers pr ON pr.id = e.provider_id';
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare($this->baseSelect() . ' WHERE e.id = :id');
        $stmt->execute([':id' => $id]);

        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare($this->baseSelect() . ' WHERE e.token = :token');
        $stmt->execute([':token' => trim($token)]);

        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findByViewToken(string $viewToken): ?array
    {
        $stmt = $this->pdo->prepare($this->baseSelect() . ' WHERE e.view_token = :view_token');
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
            $this->baseSelect() . " {$whereSql} ORDER BY e.start_date DESC LIMIT :limit OFFSET :offset"
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
                'SELECT a.session_id, es.label AS session_label, a.attendance_date, a.method, a.flagged, a.points_denied, a.reviewed_at, a.distance_m, a.flag_reason
                 FROM attendance a
                 LEFT JOIN event_sessions es ON es.id = a.session_id
                 WHERE a.registration_id = :registration_id
                 ORDER BY a.attendance_date ASC, a.session_id ASC'
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
