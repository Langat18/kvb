<?php

declare(strict_types=1);

namespace VetBoard;

use PDO;

final class PresenterBonus
{
    private const POINTS = 1;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getPdo();
    }

    // Brings stored bonuses in line with the event's current presentations.
    public function reconcile(int $eventId): void
    {
        $want = [];

        foreach ($this->entitled($eventId) as $row) {
            $want[$row['reg_no'] . '|' . $row['day']] = $row;
        }

        $have = [];

        foreach ($this->stored($eventId) as $row) {
            $have[$row['reg_no'] . '|' . $row['day']] = (int) $row['id'];
        }

        $delete = $this->pdo->prepare('DELETE FROM points_ledger WHERE id = :id');

        foreach (array_diff_key($have, $want) as $id) {
            $delete->execute([':id' => $id]);
        }

        $insert = $this->pdo->prepare(
            "INSERT INTO points_ledger (attendance_id, reg_no, event_id, points, reason, kind, bonus_date)
             VALUES (NULL, :reg, :event, :points, :reason, 'presenter_bonus', :day)
             ON CONFLICT DO NOTHING"
        );

        foreach (array_diff_key($want, $have) as $row) {
            $insert->execute([
                ':reg'    => $row['reg_no'],
                ':event'  => $eventId,
                ':points' => self::POINTS,
                ':reason' => 'Presenter bonus (' . date('d M', strtotime($row['day'])) . ')',
                ':day'    => $row['day'],
            ]);
        }
    }

    private function entitled(int $eventId): array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            SELECT DISTINCT sp.reg_no, g.d::date::text AS day
            FROM session_presentations sp
            JOIN event_sessions s ON s.id = sp.session_id AND s.active
            JOIN events e         ON e.id = s.event_id
            CROSS JOIN LATERAL generate_series(
                COALESCE(s.session_date, e.start_date)::timestamp,
                COALESCE(s.session_date, e.end_date)::timestamp,
                interval '1 day'
            ) AS g(d)
            WHERE e.id = :event
              AND sp.reg_no IS NOT NULL
              AND g.d::date BETWEEN e.start_date AND e.end_date
            SQL);
        $stmt->execute([':event' => $eventId]);

        return $stmt->fetchAll();
    }

    private function stored(int $eventId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, reg_no, bonus_date::text AS day
             FROM points_ledger
             WHERE event_id = :event AND kind = 'presenter_bonus'"
        );
        $stmt->execute([':event' => $eventId]);

        return $stmt->fetchAll();
    }
}
