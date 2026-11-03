<?php

declare(strict_types=1);

namespace VetBoard;

use PDO;
use Throwable;

final class RegistrationPurge
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getPdo();
    }

    /** @return array{name: string, signins: int, points: int}|null */
    public function summary(int $eventId, string $regNo): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id, p.name
             FROM registrations r
             JOIN participants p ON p.reg_no = r.reg_no
             WHERE r.event_id = :event AND r.reg_no = :reg'
        );
        $stmt->execute([':event' => $eventId, ':reg' => $regNo]);
        $registration = $stmt->fetch();

        if (!$registration) {
            return null;
        }

        $signins = $this->pdo->prepare('SELECT COUNT(*) FROM attendance WHERE registration_id = :registration');
        $signins->execute([':registration' => $registration['id']]);

        $points = $this->pdo->prepare(
            "SELECT COALESCE(SUM(points), 0) FROM points_ledger
             WHERE event_id = :event AND reg_no = :reg AND kind IN ('attendance', 'manual_award')"
        );
        $points->execute([':event' => $eventId, ':reg' => $regNo]);

        return [
            'name'    => (string) $registration['name'],
            'signins' => (int) $signins->fetchColumn(),
            'points'  => (int) $points->fetchColumn(),
        ];
    }

    /** @return array{ok: bool, message: string} */
    public function delete(int $eventId, string $regNo, string $reason, ?int $staffId): array
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) {
            return ['ok' => false, 'message' => 'Enter a reason of 3 to 255 characters.'];
        }

        $this->pdo->beginTransaction();

        try {
            $lock = $this->pdo->prepare(
                'SELECT r.id, p.name, e.title
                 FROM registrations r
                 JOIN participants p ON p.reg_no = r.reg_no
                 JOIN events e ON e.id = r.event_id
                 WHERE r.event_id = :event AND r.reg_no = :reg
                 FOR UPDATE OF r'
            );
            $lock->execute([':event' => $eventId, ':reg' => $regNo]);
            $registration = $lock->fetch();

            if (!$registration) {
                $this->pdo->rollBack();

                return ['ok' => false, 'message' => 'That attendee is not registered for this event.'];
            }

            $signins = $this->pdo->prepare(
                'SELECT a.attendance_date::text AS date, es.label AS session, a.method::text AS method,
                        a.flagged, a.points_denied, COALESCE(pl.points, 0) AS points
                 FROM attendance a
                 LEFT JOIN event_sessions es ON es.id = a.session_id
                 LEFT JOIN points_ledger pl ON pl.attendance_id = a.id
                 WHERE a.registration_id = :registration
                 ORDER BY a.attendance_date, a.id'
            );
            $signins->execute([':registration' => $registration['id']]);
            $attendance = $signins->fetchAll();

            $manual = $this->pdo->prepare(
                "SELECT bonus_date::text AS date, points, reason
                 FROM points_ledger
                 WHERE event_id = :event AND reg_no = :reg AND kind = 'manual_award'
                 ORDER BY id"
            );
            $manual->execute([':event' => $eventId, ':reg' => $regNo]);
            $manualRows = $manual->fetchAll();

            $points = array_sum(array_map('intval', array_column($attendance, 'points')))
                + array_sum(array_map('intval', array_column($manualRows, 'points')));

            $audit = $this->pdo->prepare(
                'INSERT INTO registration_deletions
                    (event_id, event_title, reg_no, participant, attendance_count, points_total, snapshot, reason, deleted_by)
                 VALUES (:event, :title, :reg, :participant, :signins, :points, CAST(:snapshot AS jsonb), :reason, :staff)'
            );
            $audit->execute([
                ':event'       => $eventId,
                ':title'       => $registration['title'],
                ':reg'         => $regNo,
                ':participant' => $registration['name'],
                ':signins'     => count($attendance),
                ':points'      => $points,
                ':snapshot'    => json_encode(['attendance' => $attendance, 'manual_points' => $manualRows], JSON_THROW_ON_ERROR),
                ':reason'      => $reason,
                ':staff'       => $staffId ?: null,
            ]);

            $this->pdo
                ->prepare("DELETE FROM points_ledger WHERE event_id = :event AND reg_no = :reg AND kind = 'manual_award'")
                ->execute([':event' => $eventId, ':reg' => $regNo]);

            $this->pdo
                ->prepare('DELETE FROM registrations WHERE id = :id')
                ->execute([':id' => $registration['id']]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            error_log('Registration purge failed: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'A system error occurred. Nothing was deleted.'];
        }

        error_log(sprintf('Registration purged by staff %d: %s from event %d (%s)', (int) $staffId, $regNo, $eventId, $reason));

        return ['ok' => true, 'message' => $registration['name'] . ' (' . $regNo . ') was removed from this event.'];
    }
}
