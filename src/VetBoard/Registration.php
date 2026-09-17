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

    /**
     * Records one attendance check-in. $data['points_denied'] marks a
     * check-in as PERMANENTLY ineligible for points (e.g. far outside the
     * geofence) — distinct from $data['flagged'], which means "pending
     * supervisor review." A points_denied row is never sent through the
     * quiz gate and can never be approved by a supervisor; see
     * maybeAwardPoints() and approveFlagged() for the enforcement.
     */
    public function recordAttendance(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO attendance
                (registration_id, session, method, geo_verified, submitted_lat, submitted_lng, distance_m, flagged, flag_reason, points_denied)
             VALUES
                (:registration_id, :session, :method, :geo_verified, :submitted_lat, :submitted_lng, :distance_m, :flagged, :flag_reason, :points_denied)
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
        $stmt->bindValue(':points_denied', $data['points_denied'] ?? false, PDO::PARAM_BOOL);

        $stmt->execute();

        $attendanceId = (int) $stmt->fetchColumn();

        // Points-awarding runs AFTER attendance is committed and is
        // deliberately non-fatal to this method: a participant's sign-in
        // must never be lost or reported as failed because of a points-side
        // problem. maybeAwardPoints() is idempotent, so it's always safe to
        // call again later (see reconcile_points.php) to recover any
        // attendance that slipped through here.
        if ($data['method'] !== 'virtual') {
            try {
                $this->maybeAwardPoints((int) $data['registration_id'], $data['session']);
            } catch (\Throwable $e) {
                error_log('Points award failed for attendance ' . $attendanceId . ' (non-fatal, attendance still recorded): ' . $e->getMessage());
            }
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

        try {
            $this->maybeAwardPoints((int) $row['registration_id'], $session);
        } catch (\Throwable $e) {
            error_log('Points award failed on reconfirm for attendance ' . $row['id'] . ' (non-fatal): ' . $e->getMessage());
        }

        return ['ok' => true, 'message' => 'Attendance reconfirmed. Points have been awarded if all requirements are met.'];
    }

    /**
     * Approves a flagged (out-of-geofence) check-in. Only a supervisor
     * action should call this. Refuses outright if the row is
     * points_denied — that state has no supervisor override by design.
     */
    public function approveFlagged(int $attendanceId): bool
    {
        $stmt = $this->pdo->prepare('SELECT registration_id, session, points_denied FROM attendance WHERE id = :id');
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
            $this->maybeAwardPoints((int) $row['registration_id'], $row['session']);
        } catch (\Throwable $e) {
            error_log('Points award failed on flagged-approval for attendance ' . $attendanceId . ' (non-fatal): ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Overrides an exhausted, failed quiz attempt so points can post
     * despite the participant never passing. This is a deliberate human
     * decision, distinct from the automatic 90%/2-attempt rule, which is
     * left completely unchanged — the override only ever runs when a
     * supervisor explicitly triggers it for this specific attendance row.
     * $approvedByUserId is recorded for audit purposes; pass null if the
     * caller has no authenticated user context (should not normally
     * happen, since this is gated behind a permission check upstream).
     */
    public function approveQuizOverride(int $attendanceId, ?int $approvedByUserId): bool
    {
        $stmt = $this->pdo->prepare('SELECT registration_id, session FROM attendance WHERE id = :id');
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
            $this->maybeAwardPoints((int) $row['registration_id'], $row['session']);
        } catch (\Throwable $e) {
            error_log('Points award failed on quiz-override for attendance ' . $attendanceId . ' (non-fatal): ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Kept for any existing callers that just want to clear a flag without
     * touching points. Prefer approveFlagged() for the supervisor-review
     * workflow.
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

    /** True for a PDO boolean column value regardless of driver representation (real bool vs 't'/'f'). */
    private function isTruthyDbBool($value): bool
    {
        return $value === true || $value === 't' || $value === 1 || $value === '1';
    }

    /**
     * Central gate for whether points can post for a given check-in.
     * Checked in order:
     *   1. points_denied — permanent, no override possible.
     *   2. flagged — pending supervisor review. Held until cleared.
     *   3. quiz — if configured, held until passed OR quiz_override_at is
     *      set (a supervisor explicitly released it after 2 failed
     *      attempts). The 90%/2-attempt rule itself is untouched; this is
     *      purely an escape hatch a human has to actively pull.
     * Safe to call repeatedly — it no-ops if points were already awarded.
     */
    public function maybeAwardPoints(int $registrationId, string $session): void
    {
        $attendanceId = $this->findAttendanceId($registrationId, $session);

        if ($attendanceId === null) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'SELECT a.flagged, a.points_denied, a.quiz_override_at, r.event_id, sw.id AS session_window_id
             FROM attendance a
             JOIN registrations r ON r.id = a.registration_id
             JOIN session_windows sw ON sw.item_key = a.session::text
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

        $quizModel      = new EventQuiz();
        $quizIsOverridden = $row['quiz_override_at'] !== null;

        if (!$quizIsOverridden && $quizModel->hasQuiz((int) $row['event_id'], (int) $row['session_window_id'])) {
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
     * the UI. Returns one of:
     * awarded, rejected_offsite, pending_review, pending_quiz, quiz_failed, not_applicable.
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
            'SELECT a.flagged, a.points_denied, a.quiz_override_at, r.event_id, sw.id AS session_window_id
             FROM attendance a
             JOIN registrations r ON r.id = a.registration_id
             JOIN session_windows sw ON sw.item_key = a.session::text
             WHERE a.id = :attendance_id'
        );
        $stmt->execute([':attendance_id' => $attendanceId]);
        $row = $stmt->fetch();

        if (!$row) {
            return 'not_applicable';
        }

        if ($this->isTruthyDbBool($row['points_denied'])) {
            return 'rejected_offsite';
        }

        if ($this->isTruthyDbBool($row['flagged'])) {
            return 'pending_review';
        }

        $quizModel = new EventQuiz();

        if ($row['quiz_override_at'] === null && $quizModel->hasQuiz((int) $row['event_id'], (int) $row['session_window_id'])) {
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
                    a.session, a.method, a.flagged, a.points_denied, a.quiz_override_at, a.distance_m,
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
            'SELECT a.id AS attendance_id, a.session, a.attendance_date, a.distance_m, a.flag_reason, a.confirmed_at, a.points_denied,
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

    /**
     * Attendance rows whose session has a quiz configured, the participant
     * has exhausted both attempts without passing, no override has been
     * applied yet, and points have not otherwise posted. This is the
     * supervisor's queue for the quiz-override action — deliberately
     * independent of flaggedForEvent(), since a check-in can be quiz-failed
     * without being geofence-flagged at all.
     */
    public function quizFailedForEvent(int $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id AS attendance_id, a.registration_id, a.session, a.attendance_date, a.confirmed_at,
                    p.reg_no, p.name, p.cadre
             FROM attendance a
             JOIN registrations r ON r.id = a.registration_id
             JOIN participants p ON p.reg_no = r.reg_no
             WHERE r.event_id = :event_id AND a.quiz_override_at IS NULL
             ORDER BY a.confirmed_at DESC'
        );
        $stmt->execute([':event_id' => $eventId]);
        $rows = $stmt->fetchAll();

        $quizModel    = new EventQuiz();
        $attemptModel = new QuizAttempt();
        $result       = [];

        foreach ($rows as $row) {
            $swId = $quizModel->resolveSessionWindowId($row['session']);

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
