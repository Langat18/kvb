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

        if ($data['method'] !== 'virtual') {
            $this->maybeAwardPoints((int) $data['registration_id'], $data['session']);
        }

        return $attendanceId;
    }

    public function reconfirmAttendance(int $eventId, string $regNo, string $session): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.confirmed_at, a.reconfirmed_at, a.method, a.registration_id
             FROM attendance a
             JOIN registrations r ON r.id = a.registration_id
             WHERE r.event_id = :event_id AND r.reg_no = :reg_no
               AND a.session = :session AND a.attendance_date = CURRENT_DATE'
        );
        $stmt->execute([
            ':event_id' => $eventId,
            ':reg_no'   => strtoupper(trim($regNo)),
            ':session'  => $session,
        ]);
        $row = $stmt->fetch();

        if (!$row) {
            return ['ok' => false, 'message' => 'No check-in found for this session. Please check in first.'];
        }

        if ($row['method'] !== 'virtual') {
            return ['ok' => false, 'message' => 'Reconfirmation only applies to virtual attendance.'];
        }

        if ($row['reconfirmed_at'] !== null) {
            return ['ok' => false, 'message' => 'You have already reconfirmed this session.'];
        }

        $update = $this->pdo->prepare('UPDATE attendance SET reconfirmed_at = now() WHERE id = :id');
        $update->execute([':id' => $row['id']]);

        $this->maybeAwardPoints((int) $row['registration_id'], $session);

        return ['ok' => true, 'message' => 'Attendance reconfirmed. Points have been awarded if all requirements are met.'];
    }

    /**
     * Approves a flagged (out-of-geofence) check-in. Only a supervisor
     * action should call this. Clears the flag and then attempts to award
     * points — which still won't happen yet if a quiz is configured for
     * this session and hasn't been passed.
     */
    public function approveFlagged(int $attendanceId): bool
    {
        $stmt = $this->pdo->prepare('SELECT registration_id, session FROM attendance WHERE id = :id');
        $stmt->execute([':id' => $attendanceId]);
        $row = $stmt->fetch();

        if (!$row) {
            return false;
        }

        $clear = $this->pdo->prepare('UPDATE attendance SET flagged = false, flag_reason = NULL WHERE id = :id');
        $result = $clear->execute([':id' => $attendanceId]);

        $this->maybeAwardPoints((int) $row['registration_id'], $row['session']);

        return $result;
    }

    /**
     * Kept for any existing callers that just want to clear a flag without
     * touching points. Prefer approveFlagged() for the supervisor-review
     * workflow, since it also releases points once conditions are met.
     */
    public function clearFlag(int $attendanceId): bool
    {
        $stmt = $this->pdo->prepare('UPDATE attendance SET flagged = false, flag_reason = NULL WHERE id = :id');

        return $stmt->execute([':id' => $attendanceId]);
    }

    private function findAttendanceId(int $registrationId, string $session): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM attendance
             WHERE registration_id = :rid AND session = :session AND attendance_date = CURRENT_DATE
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':rid' => $registrationId, ':session' => $session]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    /**
     * Central gate for whether points can post for a given check-in.
     * Points are held back if the check-in is still flagged, or if a quiz
     * is configured for this event+session and hasn't been passed yet.
     * Safe to call repeatedly — it no-ops if points were already awarded.
     */
    public function maybeAwardPoints(int $registrationId, string $session): void
    {
        $attendanceId = $this->findAttendanceId($registrationId, $session);

        if ($attendanceId === null) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'SELECT a.flagged, r.event_id, sw.id AS session_window_id
             FROM attendance a
             JOIN registrations r ON r.id = a.registration_id
             JOIN session_windows sw ON sw.item_key = a.session
             WHERE a.id = :attendance_id'
        );
        $stmt->execute([':attendance_id' => $attendanceId]);
        $row = $stmt->fetch();

        if (!$row || $row['flagged']) {
            return;
        }

        $quizModel = new EventQuiz();

        if ($quizModel->hasQuiz((int) $row['event_id'], (int) $row['session_window_id'])) {
            $attemptModel = new QuizAttempt();

            if (!$attemptModel->hasPassed($registrationId, (int) $row['session_window_id'])) {
                return;
            }
        }

        $alreadyStmt = $this->pdo->prepare('SELECT EXISTS(SELECT 1 FROM points_ledger WHERE attendance_id = :attendance_id)');
        $alreadyStmt->execute([':attendance_id' => $attendanceId]);

        if ((bool) $alreadyStmt->fetchColumn()) {
            return;
        }

        $this->awardPoints($attendanceId, $registrationId, $session);
    }

    /**
     * Reports why points haven't posted yet for a check-in, for display in
     * the UI (e.g. "Pending quiz", "Pending review"). Returns one of:
     * awarded, pending_review, pending_quiz, quiz_failed, not_applicable.
     */
    public function getPointsStatus(int $registrationId, string $session): string
    {
        $attendanceId = $this->findAttendanceId($registrationId, $session);

        if ($attendanceId === null) {
            return 'not_applicable';
        }

        $alreadyStmt = $this->pdo->prepare('SELECT EXISTS(SELECT 1 FROM points_ledger WHERE attendance_id = :attendance_id)');
        $alreadyStmt->execute([':attendance_id' => $attendanceId]);

        if ((bool) $alreadyStmt->fetchColumn()) {
            return 'awarded';
        }

        $stmt = $this->pdo->prepare(
            'SELECT a.flagged, r.event_id, sw.id AS session_window_id
             FROM attendance a
             JOIN registrations r ON r.id = a.registration_id
             JOIN session_windows sw ON sw.item_key = a.session
             WHERE a.id = :attendance_id'
        );
        $stmt->execute([':attendance_id' => $attendanceId]);
        $row = $stmt->fetch();

        if (!$row) {
            return 'not_applicable';
        }

        if ($row['flagged']) {
            return 'pending_review';
        }

        $quizModel = new EventQuiz();

        if ($quizModel->hasQuiz((int) $row['event_id'], (int) $row['session_window_id'])) {
            $attemptModel = new QuizAttempt();

            if ($attemptModel->hasPassed($registrationId, (int) $row['session_window_id'])) {
                return 'awarded';
            }

            if ($attemptModel->attemptCount($registrationId, (int) $row['session_window_id']) >= QuizAttempt::MAX_ATTEMPTS) {
                return 'quiz_failed';
            }

            return 'pending_quiz';
        }

        return 'not_applicable';
    }

    private function awardPoints(int $attendanceId, int $registrationId, string $session): void
    {
        $regStmt = $this->pdo->prepare(
            'SELECT r.event_id, r.reg_no FROM registrations r WHERE r.id = :registration_id'
        );
        $regStmt->execute([':registration_id' => $registrationId]);
        $row = $regStmt->fetch();

        if (!$row) {
            return;
        }

        $pointsStmt = $this->pdo->prepare(
            'SELECT esp.points
             FROM event_session_points esp
             JOIN session_windows sw ON sw.id = esp.session_window_id
             WHERE esp.event_id = :event_id AND sw.item_key = :session'
        );
        $pointsStmt->execute([':event_id' => $row['event_id'], ':session' => $session]);
        $sessionPoints = (int) $pointsStmt->fetchColumn();

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
}
