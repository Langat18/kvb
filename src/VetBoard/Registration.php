<?php

declare(strict_types=1);

namespace VetBoard;

use PDO;

class Registration
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getPdo();
    }

    public function find(int $eventId, string $regNo): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM registrations WHERE event_id = :event_id AND reg_no = :reg_no');
        $stmt->execute([':event_id' => $eventId, ':reg_no' => strtoupper(trim($regNo))]);

        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function create(int $eventId, string $regNo, string $signatureImage): int
    {
        if (!str_starts_with($signatureImage, 'data:image/')) {
            throw new \InvalidArgumentException('A valid signature image is required.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO registrations (event_id, reg_no, signature_image)
             VALUES (:event_id, :reg_no, :signature_image)
             RETURNING id'
        );
        $stmt->execute([
            ':event_id'        => $eventId,
            ':reg_no'          => strtoupper(trim($regNo)),
            ':signature_image' => $signatureImage,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function hasSignedSession(int $registrationId, string $session): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM attendance WHERE registration_id = :registration_id AND attendance_date = CURRENT_DATE AND session = :session'
        );
        $stmt->execute([':registration_id' => $registrationId, ':session' => $session]);

        return (bool) $stmt->fetch();
    }

    public function recordAttendance(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO attendance
                (registration_id, session, method, geo_verified, submitted_lat, submitted_lng, distance_m, flagged, flag_reason)
             VALUES
                (:registration_id, :session, :method, :geo_verified, :submitted_lat, :submitted_lng, :distance_m, :flagged, :flag_reason)
             RETURNING id'
        );

        $stmt->bindValue(':registration_id', $data['registration_id'], PDO::PARAM_INT);
        $stmt->bindValue(':session', $data['session'], PDO::PARAM_STR);
        $stmt->bindValue(':method', $data['method'], PDO::PARAM_STR);

        if ($data['geo_verified'] === null) {
            $stmt->bindValue(':geo_verified', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':geo_verified', $data['geo_verified'], PDO::PARAM_BOOL);
        }

        $stmt->bindValue(':submitted_lat', $data['submitted_lat'], $data['submitted_lat'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':submitted_lng', $data['submitted_lng'], $data['submitted_lng'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':distance_m', $data['distance_m'], $data['distance_m'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':flagged', $data['flagged'], PDO::PARAM_BOOL);
        $stmt->bindValue(':flag_reason', $data['flag_reason'], $data['flag_reason'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);

        $stmt->execute();

        $attendanceId = (int) $stmt->fetchColumn();

        $this->awardPoints($attendanceId, (int) $data['registration_id'], $data['session']);

        return $attendanceId;
    }

    private function awardPoints(int $attendanceId, int $registrationId, string $session): void
    {
        $regStmt = $this->pdo->prepare(
            'SELECT r.event_id, r.reg_no,
                    e.points_morning, e.points_mid_morning, e.points_afternoon
             FROM registrations r
             JOIN events e ON e.id = r.event_id
             WHERE r.id = :registration_id'
        );
        $regStmt->execute([':registration_id' => $registrationId]);
        $row = $regStmt->fetch();

        if (!$row) {
            return;
        }

        $sessionPointsMap = [
            'morning'     => $row['points_morning'],
            'mid_morning' => $row['points_mid_morning'],
            'afternoon'   => $row['points_afternoon'],
        ];

        $sessionPoints = (int) ($sessionPointsMap[$session] ?? 0);

        if ($sessionPoints <= 0) {
            return;
        }

        $todayStmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(points), 0) FROM points_ledger
             WHERE event_id = :event_id AND reg_no = :reg_no
             AND awarded_at::date = CURRENT_DATE"
        );
        $todayStmt->execute([
            ':event_id' => $row['event_id'],
            ':reg_no'   => $row['reg_no'],
        ]);
        $awardedToday = (int) $todayStmt->fetchColumn();

        $remaining = max(0, 8 - $awardedToday);
        $toAward   = min($sessionPoints, $remaining);

        if ($toAward <= 0) {
            return;
        }

        $insertStmt = $this->pdo->prepare(
            'INSERT INTO points_ledger (attendance_id, reg_no, event_id, points, reason)
             VALUES (:attendance_id, :reg_no, :event_id, :points, :reason)'
        );
        $insertStmt->execute([
            ':attendance_id' => $attendanceId,
            ':reg_no'        => $row['reg_no'],
            ':event_id'      => $row['event_id'],
            ':points'        => $toAward,
            ':reason'        => ucfirst(str_replace('_', '-', $session)) . ' session attendance',
        ]);
    }


    public function attendanceDatesForEvent(int $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT attendance_date FROM attendance a
             JOIN registrations r ON r.id = a.registration_id
             WHERE r.event_id = :event_id
             ORDER BY attendance_date ASC'
        );
        $stmt->execute([':event_id' => $eventId]);

        return array_column($stmt->fetchAll(), 'attendance_date');
    }

    public function attendanceRowsForDate(int $eventId, string $date): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.reg_no, p.name, p.cadre, p.email, p.phone,
                    a.session, a.method, a.flagged, a.distance_m,
                    COALESCE(pl.points, 0) AS points_awarded
             FROM attendance a
             JOIN registrations r ON r.id = a.registration_id
             JOIN participants p ON p.reg_no = r.reg_no
             LEFT JOIN points_ledger pl ON pl.attendance_id = a.id
             WHERE r.event_id = :event_id AND a.attendance_date = :date
             ORDER BY p.name ASC'
        );
        $stmt->execute([':event_id' => $eventId, ':date' => $date]);

        return $stmt->fetchAll();
    }

    public function flaggedForEvent(int $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id AS attendance_id, a.session, a.attendance_date, a.distance_m, a.flag_reason, a.confirmed_at,
                    p.reg_no, p.name, p.cadre
             FROM attendance a
             JOIN registrations r ON r.id = a.registration_id
             JOIN participants p ON p.reg_no = r.reg_no
             WHERE r.event_id = :event_id AND a.flagged = true
             ORDER BY a.confirmed_at DESC'
        );
        $stmt->execute([':event_id' => $eventId]);

        return $stmt->fetchAll();
    }

    public function clearFlag(int $attendanceId): bool
    {
        $stmt = $this->pdo->prepare('UPDATE attendance SET flagged = false, flag_reason = NULL WHERE id = :id');

        return $stmt->execute([':id' => $attendanceId]);
    }
}
