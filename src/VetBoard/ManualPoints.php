<?php

declare(strict_types=1);

namespace VetBoard;

use DateTimeImmutable;
use PDO;
use Throwable;

final class ManualPoints
{
    public const DAILY_CAP = Points::DAILY_CAP;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getPdo();
    }

    public function award(
        int $eventId,
        string $regNo,
        int $sessionId,
        string $date,
        int|float|string $points,
        string $reason,
        ?int $staffId
    ): array {
        $reason = trim($reason);
        $tenths = Points::parseTenths($points);

        if ($tenths === null || $tenths < 1 || $tenths > Points::DAILY_CAP_TENTHS) {
            return $this->result(false, 'Points must be between 0.1 and ' . self::DAILY_CAP . ', with at most one decimal place.');
        }

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 150) {
            return $this->result(false, 'Enter a reason of 3 to 150 characters.');
        }

        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($day === false || $day->format('Y-m-d') !== $date) {
            return $this->result(false, 'Enter a valid date.');
        }

        $this->pdo->beginTransaction();

        try {
            $registration = $this->fetch(
                'SELECT r.id FROM registrations r WHERE r.event_id = :event AND r.reg_no = :reg FOR UPDATE',
                [':event' => $eventId, ':reg' => $regNo]
            );

            if ($registration === null) {
                return $this->fail('That attendee is not registered for this event.');
            }

            $event = $this->fetch(
                'SELECT start_date::text AS start_date, end_date::text AS end_date FROM events WHERE id = :event',
                [':event' => $eventId]
            );

            if ($event === null || $date < $event['start_date'] || $date > $event['end_date']) {
                return $this->fail('The date must fall within the event dates.');
            }

            $session = $this->fetch(
                'SELECT label, session_date::text AS session_date
                 FROM event_sessions WHERE id = :session AND event_id = :event AND active',
                [':session' => $sessionId, ':event' => $eventId]
            );

            if ($session === null) {
                return $this->fail('Choose a session of this event.');
            }

            if ($session['session_date'] !== null && $session['session_date'] !== $date) {
                return $this->fail('That session takes place on ' . date('d M Y', strtotime($session['session_date'])) . '.');
            }

            $earned = Points::tenths($this->scalar(
                'SELECT COALESCE(SUM(pl.points), 0)
                 FROM points_ledger pl
                 JOIN attendance a ON a.id = pl.attendance_id
                 WHERE a.registration_id = :registration AND a.attendance_date = :day AND a.session_id = :session',
                [':registration' => $registration['id'], ':day' => $date, ':session' => $sessionId]
            ));

            if ($earned > 0) {
                return $this->fail('This attendee already earned points for that session on that day.');
            }

            $existing = $this->scalar(
                "SELECT 1 FROM points_ledger
                 WHERE kind = 'manual_award' AND event_id = :event AND reg_no = :reg
                   AND session_id = :session AND bonus_date = :day",
                [':event' => $eventId, ':reg' => $regNo, ':session' => $sessionId, ':day' => $date]
            );

            if ($existing) {
                return $this->fail('A manual award already exists for that session and day. Remove it first to change it.');
            }

            $dayTotal = Points::tenths($this->scalar(
                "SELECT COALESCE(SUM(pl.points), 0)
                 FROM points_ledger pl
                 LEFT JOIN attendance a ON a.id = pl.attendance_id
                 WHERE pl.event_id = :event AND pl.reg_no = :reg
                   AND ((pl.kind = 'attendance' AND a.attendance_date = :signin_day)
                     OR (pl.kind = 'manual_award' AND pl.bonus_date = :manual_day))",
                [':event' => $eventId, ':reg' => $regNo, ':signin_day' => $date, ':manual_day' => $date]
            ));

            $remaining = Points::DAILY_CAP_TENTHS - $dayTotal;

            if ($tenths > $remaining) {
                $left = Points::format(Points::fromTenths(max(0, $remaining)));

                return $this->fail($remaining > 0
                    ? "Only {$left} more point(s) can be added for that day (daily limit " . self::DAILY_CAP . ').'
                    : 'The daily limit of ' . self::DAILY_CAP . ' points has already been reached for that day.');
            }

            $entry = $this->pdo->prepare(
                "INSERT INTO points_ledger
                    (attendance_id, reg_no, event_id, points, reason, kind, bonus_date, session_id, awarded_by)
                 VALUES (NULL, :reg, :event, :points, :reason, 'manual_award', :day, :session, :staff)"
            );
            $entry->execute([
                ':reg'     => $regNo,
                ':event'   => $eventId,
                ':points'  => number_format($tenths / 10, 1, '.', ''),
                ':reason'  => mb_substr('Manual award: ' . $session['label'] . ' (' . date('d M', strtotime($date)) . ') - ' . $reason, 0, 255),
                ':day'     => $date,
                ':session' => $sessionId,
                ':staff'   => $staffId ?: null,
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            error_log('Manual points award failed: ' . $e->getMessage());

            return $this->result(false, 'A system error occurred. Please try again.');
        }

        $label = Points::format($tenths / 10);

        error_log(sprintf(
            'Manual points: staff %d awarded %s to %s, event %d, session %d, day %s',
            (int) $staffId, $label, $regNo, $eventId, $sessionId, $date
        ));

        return $this->result(true, ($label === '1' ? '1 point' : "{$label} points") . ' added.');
    }

   
    public function remove(int $eventId, string $regNo, int $ledgerId, ?int $staffId): array
    {
        try {
            $row = $this->fetch(
                "DELETE FROM points_ledger
                 WHERE id = :id AND event_id = :event AND reg_no = :reg AND kind = 'manual_award'
                 RETURNING points, reason",
                [':id' => $ledgerId, ':event' => $eventId, ':reg' => $regNo]
            );
        } catch (Throwable $e) {
            error_log('Manual points removal failed: ' . $e->getMessage());

            return $this->result(false, 'A system error occurred. Please try again.');
        }

        if ($row === null) {
            return $this->result(false, 'That entry no longer exists.');
        }

        error_log(sprintf(
            'Manual points: staff %d removed %s from %s, event %d (%s)',
            (int) $staffId, Points::format($row['points']), $regNo, $eventId, $row['reason']
        ));

        return $this->result(true, 'Manual points removed.');
    }

    public function listFor(int $eventId, string $regNo): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT pl.id, pl.points, pl.reason, pl.bonus_date::text AS day, es.label
             FROM points_ledger pl
             LEFT JOIN event_sessions es ON es.id = pl.session_id
             WHERE pl.event_id = :event AND pl.reg_no = :reg AND pl.kind = 'manual_award'
             ORDER BY pl.bonus_date, pl.id"
        );
        $stmt->execute([':event' => $eventId, ':reg' => $regNo]);

        return $stmt->fetchAll();
    }

    private function fetch(string $sql, array $params): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    private function fail(string $message): array
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }

        return $this->result(false, $message);
    }

    private function result(bool $ok, string $message): array
    {
        return ['ok' => $ok, 'message' => $message];
    }
}
