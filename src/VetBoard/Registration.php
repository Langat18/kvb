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

        return (int) $stmt->fetchColumn();
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
