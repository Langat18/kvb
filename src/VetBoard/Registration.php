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

    public function hasSignedSession(int $registrationId, int $sessionId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM attendance WHERE registration_id = :registration_id AND attendance_date = CURRENT_DATE AND session_id = :session_id'
        );
        $stmt->execute([':registration_id' => $registrationId, ':session_id' => $sessionId]);

        return (bool) $stmt->fetch();
    }

    // True if this device has already recorded a self-scan (never a staff
    // manual entry) for a DIFFERENT registration in this exact session.
    public function deviceSignedDifferentReg(int $sessionId, string $deviceId, int $registrationId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM attendance
             WHERE session_id = :session_id AND device_id = :device_id
               AND registration_id != :registration_id
               AND recorded_by_staff_id IS NULL
             LIMIT 1'
        );
        $stmt->execute([
            ':session_id'      => $sessionId,
            ':device_id'       => $deviceId,
            ':registration_id' => $registrationId,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    // points_denied = permanently ineligible (e.g. way offsite), distinct
    // from flagged = pending supervisor review.
    public function recordAttendance(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO attendance
                (registration_id, session_id, method, geo_verified, submitted_lat, submitted_lng, distance_m, flagged, flag_reason, points_denied, recorded_by_staff_id, device_id)
             VALUES
                (:registration_id, :session_id, :method, :geo_verified, :submitted_lat, :submitted_lng, :distance_m, :flagged, :flag_reason, :points_denied, :recorded_by_staff_id, :device_id)
             RETURNING id'
        );

        $stmt->bindValue(':registration_id', $data['registration_id'], PDO::PARAM_INT);
        $stmt->bindValue(':session_id', $data['session_id'], PDO::PARAM_INT);
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
        $stmt->bindValue(':points_denied', $data['points_denied'] ?? false, PDO::PARAM_BOOL);
        $recordedBy = $data['recorded_by_staff_id'] ?? null;
        $stmt->bindValue(':recorded_by_staff_id', $recordedBy, $recordedBy === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $deviceId = $data['device_id'] ?? null;
        $stmt->bindValue(':device_id', $deviceId, $deviceId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);

        $stmt->execute();

        $attendanceId = (int) $stmt->fetchColumn();

        // Non-fatal: a points-side failure must never lose an attendance
        // record. maybeAwardPoints() is idempotent, safe to retry later.
        try {
            $this->maybeAwardPoints((int) $data['registration_id'], (int) $data['session_id']);
        } catch (\Throwable $e) {
            error_log('Points award failed for attendance ' . $attendanceId . ' (non-fatal): ' . $e->getMessage());
        }

        return $attendanceId;
    }

    // Approves a flagged (out-of-geofence) check-in. Refuses on points_denied — no override possible there.
    public function approveFlagged(int $attendanceId): bool
    {
        $stmt = $this->pdo->prepare('SELECT registration_id, session_id, points_denied FROM attendance WHERE id = :id');
        $stmt->execute([':id' => $attendanceId]);
        $row = $stmt->fetch();

        if (!$row) {
            return false;
        }

        if ($this->isTruthyDbBool($row['points_denied'])) {
            return false;
        }

        $clear = $this->pdo->prepare('UPDATE attendance SET flagged = false, flag_reason = NULL WHERE id = :id');
        $result = $clear->execute([':id' => $attendanceId]);

        try {
            $this->maybeAwardPointsForAttendance($attendanceId);
        } catch (\Throwable $e) {
            error_log('Points award failed on flagged-approval for attendance ' . $attendanceId . ' (non-fatal): ' . $e->getMessage());
        }

        return $result;
    }

    // Supervisor override for an exhausted, failed quiz. Distinct from the
    // automatic 90%/2-attempt rule, which this does not change.
    public function approveQuizOverride(int $attendanceId, ?int $approvedByUserId): bool
    {
        $stmt = $this->pdo->prepare('SELECT registration_id, session_id FROM attendance WHERE id = :id');
        $stmt->execute([':id' => $attendanceId]);
        $row = $stmt->fetch();

        if (!$row) {
            return false;
        }

        $update = $this->pdo->prepare(
            'UPDATE attendance SET quiz_override_by = :by, quiz_override_at = now() WHERE id = :id'
        );
        $update->bindValue(':by', $approvedByUserId, $approvedByUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $update->bindValue(':id', $attendanceId, PDO::PARAM_INT);
        $result = $update->execute();

        try {
            $this->maybeAwardPointsForAttendance($attendanceId);
        } catch (\Throwable $e) {
            error_log('Points award failed on quiz-override for attendance ' . $attendanceId . ' (non-fatal): ' . $e->getMessage());
        }

        return $result;
    }

    // Rejects a pending review item (flagged geofence or quiz failure).
    
    public function denyAttendance(int $attendanceId, ?int $deniedByUserId): bool
    {
        $alreadyStmt = $this->pdo->prepare('SELECT EXISTS(SELECT 1 FROM points_ledger WHERE attendance_id = :id)');
        $alreadyStmt->execute([':id' => $attendanceId]);

        if ((bool) $alreadyStmt->fetchColumn()) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE attendance
             SET points_denied = true, flagged = false, flag_reason = NULL,
                 denied_by = :denied_by, denied_at = now()
             WHERE id = :id'
        );
        $stmt->bindValue(':id', $attendanceId, PDO::PARAM_INT);
        $stmt->bindValue(':denied_by', $deniedByUserId, $deniedByUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);

        return $stmt->execute();
    }

    // Clears a flag without touching points. Prefer approveFlagged() for the review workflow.
    public function clearFlag(int $attendanceId): bool
    {
        $stmt = $this->pdo->prepare('UPDATE attendance SET flagged = false, flag_reason = NULL WHERE id = :id');

        return $stmt->execute([':id' => $attendanceId]);
    }

    private function findAttendanceId(int $registrationId, int $sessionId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM attendance
             WHERE registration_id = :rid AND session_id = :session_id AND attendance_date = CURRENT_DATE
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':rid' => $registrationId, ':session_id' => $sessionId]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    // True for a PDO boolean column regardless of driver representation (real bool vs 't'/'f').
    private function isTruthyDbBool($value): bool
    {
        return $value === true || $value === 't' || $value === 1 || $value === '1';
    }

    // Central points gate. Order: points_denied (permanent) > flagged
    // (pending review) > quiz (virtual method only — onsite is already
    // verified by geofence). Idempotent — no-ops if already awarded.
    public function maybeAwardPoints(int $registrationId, int $sessionId): void
    {
        $attendanceId = $this->findAttendanceId($registrationId, $sessionId);

        if ($attendanceId === null) {
            return;
        }

        $this->maybeAwardPointsForAttendance($attendanceId);
    }

    // Same gate as maybeAwardPoints, keyed on the attendance row itself so a
    // review made on a later day still awards against the attendance date.
    public function maybeAwardPointsForAttendance(int $attendanceId): void
    {
        $idStmt = $this->pdo->prepare('SELECT registration_id, session_id FROM attendance WHERE id = :id');
        $idStmt->execute([':id' => $attendanceId]);
        $ids = $idStmt->fetch();

        if (!$ids || $ids['session_id'] === null) {
            return;
        }

        $registrationId = (int) $ids['registration_id'];
        $sessionId      = (int) $ids['session_id'];

        $stmt = $this->pdo->prepare(
            'SELECT a.method, a.flagged, a.points_denied, a.quiz_override_at, r.event_id, a.session_id
             FROM attendance a
             JOIN registrations r ON r.id = a.registration_id
             WHERE a.id = :attendance_id'
        );
        $stmt->execute([':attendance_id' => $attendanceId]);
        $row = $stmt->fetch();

        if (!$row) {
            return;
        }

        if ($this->isTruthyDbBool($row['points_denied'])) {
            return;
        }

        if ($this->isTruthyDbBool($row['flagged'])) {
            return;
        }

        if ($row['method'] === 'virtual') {
            $quizModel        = new EventQuiz();
            $quizIsOverridden = $row['quiz_override_at'] !== null;

            if (!$quizIsOverridden && $quizModel->hasQuiz((int) $row['event_id'], (int) $row['session_id'])) {
                $attemptModel = new QuizAttempt();

                if (!$attemptModel->hasPassed($registrationId, (int) $row['session_id'])) {
                    return;
                }
            }
        }

        $alreadyStmt = $this->pdo->prepare('SELECT EXISTS(SELECT 1 FROM points_ledger WHERE attendance_id = :attendance_id)');
        $alreadyStmt->execute([':attendance_id' => $attendanceId]);

        if ((bool) $alreadyStmt->fetchColumn()) {
            return;
        }

        $this->awardPoints($attendanceId, $registrationId, $sessionId);
    }

    // Returns: awarded, rejected_offsite, denied_by_supervisor, pending_review, pending_quiz, quiz_failed, not_applicable.
    // rejected_offsite = automatic hard-distance rejection at check-in time.
    // denied_by_supervisor = a human decision via denyAttendance(), distinguished by denied_by being set.
    public function getPointsStatus(int $registrationId, int $sessionId): string
    {
        $attendanceId = $this->findAttendanceId($registrationId, $sessionId);

        if ($attendanceId === null) {
            return 'not_applicable';
        }

        $alreadyStmt = $this->pdo->prepare('SELECT EXISTS(SELECT 1 FROM points_ledger WHERE attendance_id = :attendance_id)');
        $alreadyStmt->execute([':attendance_id' => $attendanceId]);

        if ((bool) $alreadyStmt->fetchColumn()) {
            return 'awarded';
        }

        $stmt = $this->pdo->prepare(
            'SELECT a.method, a.flagged, a.points_denied, a.denied_by, a.quiz_override_at, r.event_id, a.session_id
             FROM attendance a
             JOIN registrations r ON r.id = a.registration_id
             WHERE a.id = :attendance_id'
        );
        $stmt->execute([':attendance_id' => $attendanceId]);
        $row = $stmt->fetch();

        if (!$row) {
            return 'not_applicable';
        }

        if ($this->isTruthyDbBool($row['points_denied'])) {
            return $row['denied_by'] !== null ? 'denied_by_supervisor' : 'rejected_offsite';
        }

        if ($this->isTruthyDbBool($row['flagged'])) {
            return 'pending_review';
        }

        if ($row['method'] === 'virtual') {
            $quizModel = new EventQuiz();

            if ($row['quiz_override_at'] === null && $quizModel->hasQuiz((int) $row['event_id'], (int) $row['session_id'])) {
                $attemptModel = new QuizAttempt();

                if ($attemptModel->hasPassed($registrationId, (int) $row['session_id'])) {
                    return 'awarded';
                }

                if ($attemptModel->attemptCount($registrationId, (int) $row['session_id']) >= QuizAttempt::MAX_ATTEMPTS) {
                    return 'quiz_failed';
                }

                return 'pending_quiz';
            }
        }

        return 'not_applicable';
    }

    private function awardPoints(int $attendanceId, int $registrationId, int $sessionId): void
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
            'SELECT points, label FROM event_sessions WHERE id = :session_id AND event_id = :event_id'
        );
        $pointsStmt->execute([':session_id' => $sessionId, ':event_id' => $row['event_id']]);
        $sessionRow    = $pointsStmt->fetch();
        $sessionTenths = $sessionRow ? Points::tenths($sessionRow['points']) : 0;

        if ($sessionTenths <= 0) {
            return;
        }

        $todayStmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(pl.points), 0) FROM points_ledger pl
             JOIN attendance pa ON pa.id = pl.attendance_id
             WHERE pl.event_id = :event_id AND pl.reg_no = :reg_no
             AND pa.attendance_date = (SELECT attendance_date FROM attendance WHERE id = :attendance_id)"
        );
        $todayStmt->execute([
            ':attendance_id' => $attendanceId,
            ':event_id' => $row['event_id'],
            ':reg_no'   => $row['reg_no'],
        ]);
        $awardedToday = Points::tenths($todayStmt->fetchColumn());

        $remaining = max(0, Points::DAILY_CAP_TENTHS - $awardedToday);
        $toAward   = min($sessionTenths, $remaining);

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
            ':points'        => number_format($toAward / 10, 1, '.', ''),
            ':reason'        => ($sessionRow['label'] ?? 'Session') . ' attendance',
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
                    a.session_id, es.label AS session_label, a.method, a.flagged, a.points_denied, a.quiz_override_at, a.distance_m,
                    COALESCE(pl.points, 0) AS points_awarded
             FROM attendance a
             JOIN registrations r ON r.id = a.registration_id
             JOIN participants p ON p.reg_no = r.reg_no
             LEFT JOIN points_ledger pl ON pl.attendance_id = a.id
             LEFT JOIN event_sessions es ON es.id = a.session_id
             WHERE r.event_id = :event_id AND a.attendance_date = :date
             ORDER BY p.name ASC'
        );
        $stmt->execute([':event_id' => $eventId, ':date' => $date]);

        return $stmt->fetchAll();
    }

    // Applies a reviewer's decision to an attendance record. Approve also
    // reverses an automatic rejection. Refused once points have been awarded.
    public function reviewAttendance(int $attendanceId, string $decision, ?int $staffId, ?string $note = null): array
    {
        if (!in_array($decision, ['approve', 'deny'], true)) {
            return ['ok' => false, 'message' => 'Unknown action. Nothing was changed.'];
        }

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare(
                'SELECT registration_id, session_id FROM attendance WHERE id = :id FOR UPDATE'
            );
            $stmt->execute([':id' => $attendanceId]);
            $row = $stmt->fetch();

            if (!$row) {
                $this->pdo->rollBack();
                return ['ok' => false, 'message' => 'That record no longer exists.'];
            }

            $ledger = $this->pdo->prepare('SELECT EXISTS(SELECT 1 FROM points_ledger WHERE attendance_id = :id)');
            $ledger->execute([':id' => $attendanceId]);

            if ((bool) $ledger->fetchColumn()) {
                $this->pdo->rollBack();
                return ['ok' => false, 'message' => 'Points were already awarded for this record, so it cannot be changed.'];
            }

            if ($decision === 'approve') {
                $sql = 'UPDATE attendance
                        SET flagged = false, flag_reason = NULL, points_denied = false,
                            denied_by = NULL, denied_at = NULL,
                            reviewed_by = :reviewed_by, reviewed_at = now(), review_note = :note
                        WHERE id = :id';
            } else {
                $sql = 'UPDATE attendance
                        SET flagged = false, flag_reason = NULL, points_denied = true,
                            denied_by = :denied_by, denied_at = now(),
                            reviewed_by = :reviewed_by, reviewed_at = now(), review_note = :note
                        WHERE id = :id';
            }

            $upd = $this->pdo->prepare($sql);
            $upd->bindValue(':id', $attendanceId, PDO::PARAM_INT);
            $upd->bindValue(':reviewed_by', $staffId, $staffId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $upd->bindValue(':note', $note, $note === null ? PDO::PARAM_NULL : PDO::PARAM_STR);

            if ($decision === 'deny') {
                $upd->bindValue(':denied_by', $staffId, $staffId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            }

            $upd->execute();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('[flagged] review failed for attendance ' . $attendanceId . ': ' . $e->getMessage());

            return ['ok' => false, 'message' => 'A database error stopped this change. Nothing was changed.'];
        }

        if ($decision === 'deny') {
            return ['ok' => true, 'message' => 'Record denied. No points will be awarded for this check-in.'];
        }

        try {
            $this->maybeAwardPointsForAttendance($attendanceId);
        } catch (\Throwable $e) {
            error_log('Points award failed on review of attendance ' . $attendanceId . ' (non-fatal): ' . $e->getMessage());
        }

        $awarded = $this->pdo->prepare('SELECT EXISTS(SELECT 1 FROM points_ledger WHERE attendance_id = :id)');
        $awarded->execute([':id' => $attendanceId]);

        return [
            'ok'      => true,
            'message' => ((bool) $awarded->fetchColumn())
                ? 'Record approved and points awarded.'
                : 'Record approved. Points post once any other requirements (e.g. a session quiz) are met.',
        ];
    }

    // Returns a reviewed record to the pending list. Refused once points exist.
    public function reopenReview(int $attendanceId): array
    {
        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare('SELECT reviewed_at FROM attendance WHERE id = :id FOR UPDATE');
            $stmt->execute([':id' => $attendanceId]);
            $row = $stmt->fetch();

            if (!$row) {
                $this->pdo->rollBack();
                return ['ok' => false, 'message' => 'That record no longer exists.'];
            }

            if ($row['reviewed_at'] === null) {
                $this->pdo->rollBack();
                return ['ok' => false, 'message' => 'That record has not been reviewed.'];
            }

            $ledger = $this->pdo->prepare('SELECT EXISTS(SELECT 1 FROM points_ledger WHERE attendance_id = :id)');
            $ledger->execute([':id' => $attendanceId]);

            if ((bool) $ledger->fetchColumn()) {
                $this->pdo->rollBack();
                return ['ok' => false, 'message' => 'Points were already awarded for this record, so it cannot be reopened.'];
            }

            $upd = $this->pdo->prepare(
                "UPDATE attendance
                 SET flagged = true, flag_reason = 'Returned to review', points_denied = false,
                     denied_by = NULL, denied_at = NULL,
                     reviewed_by = NULL, reviewed_at = NULL, review_note = NULL
                 WHERE id = :id"
            );
            $upd->execute([':id' => $attendanceId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('[flagged] reopen failed for attendance ' . $attendanceId . ': ' . $e->getMessage());

            return ['ok' => false, 'message' => 'A database error stopped this change. Nothing was changed.'];
        }

        return ['ok' => true, 'message' => 'Record returned to the pending list.'];
    }

    public function recentlyReviewedForEvent(int $eventId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id AS attendance_id, a.attendance_date, a.reviewed_by, a.reviewed_at, a.review_note, a.points_denied,
                    es.label AS session_label, p.reg_no, p.name,
                    EXISTS(SELECT 1 FROM points_ledger pl WHERE pl.attendance_id = a.id) AS has_points
             FROM attendance a
             JOIN registrations r ON r.id = a.registration_id
             JOIN participants p ON p.reg_no = r.reg_no
             LEFT JOIN event_sessions es ON es.id = a.session_id
             WHERE r.event_id = :event_id AND a.reviewed_at IS NOT NULL
             ORDER BY a.reviewed_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function flaggedForEvent(int $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id AS attendance_id, a.session_id, es.label AS session_label, a.attendance_date, a.distance_m, a.flag_reason, a.confirmed_at, a.points_denied,
                    p.reg_no, p.name, p.cadre
             FROM attendance a
             JOIN registrations r ON r.id = a.registration_id
             JOIN participants p ON p.reg_no = r.reg_no
             LEFT JOIN event_sessions es ON es.id = a.session_id
             WHERE r.event_id = :event_id AND a.flagged = true
             ORDER BY a.confirmed_at DESC'
        );
        $stmt->execute([':event_id' => $eventId]);

        return $stmt->fetchAll();
    }

    // Supervisor queue for quiz overrides. Virtual-only — onsite never gets gated by a quiz.
    // Excludes denied records (points_denied) so they stop appearing once a supervisor has acted.
    public function quizFailedForEvent(int $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id AS attendance_id, a.registration_id, a.session_id, es.label AS session_label, a.attendance_date, a.confirmed_at,
                    p.reg_no, p.name, p.cadre
             FROM attendance a
             JOIN registrations r ON r.id = a.registration_id
             JOIN participants p ON p.reg_no = r.reg_no
             LEFT JOIN event_sessions es ON es.id = a.session_id
             WHERE r.event_id = :event_id AND a.method = \'virtual\' AND a.quiz_override_at IS NULL AND a.points_denied IS NOT TRUE
             ORDER BY a.confirmed_at DESC'
        );
        $stmt->execute([':event_id' => $eventId]);
        $rows = $stmt->fetchAll();

        $quizModel    = new EventQuiz();
        $attemptModel = new QuizAttempt();
        $result       = [];

        foreach ($rows as $row) {
            $swId = $row['session_id'] !== null ? (int) $row['session_id'] : null;

            if ($swId === null || !$quizModel->hasQuiz($eventId, $swId)) {
                continue;
            }

            if ($attemptModel->hasPassed((int) $row['registration_id'], $swId)) {
                continue;
            }

            if ($attemptModel->attemptCount((int) $row['registration_id'], $swId) < QuizAttempt::MAX_ATTEMPTS) {
                continue;
            }

            $alreadyStmt = $this->pdo->prepare('SELECT EXISTS(SELECT 1 FROM points_ledger WHERE attendance_id = :id)');
            $alreadyStmt->execute([':id' => $row['attendance_id']]);

            if ((bool) $alreadyStmt->fetchColumn()) {
                continue;
            }

            $row['best_score'] = $attemptModel->bestScore((int) $row['registration_id'], $swId);
            $result[]          = $row;
        }

        return $result;
    }
}
