<?php

declare(strict_types=1);

namespace VetBoard;

use PDO;

final class PresenterBonus
{
    private const POINTS_PER_PRESENTATION = 1;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getPdo();
    }

    // Brings stored bonuses in line with the event's current presentations.
    /** @return array{added: int, updated: int, removed: int} */
    public function reconcile(int $eventId): array
    {
        $want = [];

        foreach ($this->entitled($eventId) as $row) {
            $count = (int) $row['presentations'];

            $want[$row['reg_no'] . '|' . $row['day']] = [
                'reg_no' => $row['reg_no'],
                'day'    => $row['day'],
                'count'  => $count,
                'points' => $count * self::POINTS_PER_PRESENTATION,
            ];
        }

        $have = [];

        foreach ($this->stored($eventId) as $row) {
            $have[$row['reg_no'] . '|' . $row['day']] = ['id' => (int) $row['id'], 'points' => (float) $row['points']];
        }

        $changes = ['added' => 0, 'updated' => 0, 'removed' => 0];

        $delete = $this->pdo->prepare('DELETE FROM points_ledger WHERE id = :id');

        foreach (array_diff_key($have, $want) as $row) {
            $delete->execute([':id' => $row['id']]);
            $changes['removed']++;
        }

        $update = $this->pdo->prepare('UPDATE points_ledger SET points = :points, reason = :reason WHERE id = :id');

        foreach (array_intersect_key($have, $want) as $key => $row) {
            if (abs($row['points'] - $want[$key]['points']) > 0.001) {
                $update->execute([
                    ':points' => $want[$key]['points'],
                    ':reason' => $this->reason($want[$key]['day'], $want[$key]['count']),
                    ':id'     => $row['id'],
                ]);
                $changes['updated']++;
            }
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
                ':points' => $row['points'],
                ':reason' => $this->reason($row['day'], $row['count']),
                ':day'    => $row['day'],
            ]);
            $changes['added'] += $insert->rowCount();
        }

        return $changes;
    }

    private function reason(string $day, int $count): string
    {
        $label = 'Presenter bonus (' . date('d M', strtotime($day)) . ')';

        return $count > 1 ? "{$label}: {$count} presentations" : $label;
    }

    private function entitled(int $eventId): array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            SELECT sp.reg_no, g.d::date::text AS day, COUNT(*) AS presentations
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
            GROUP BY 1, 2
            SQL);
        $stmt->execute([':event' => $eventId]);

        return $stmt->fetchAll();
    }

    private function stored(int $eventId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, reg_no, bonus_date::text AS day, points
             FROM points_ledger
             WHERE event_id = :event AND kind = 'presenter_bonus'"
        );
        $stmt->execute([':event' => $eventId]);

        return $stmt->fetchAll();
    }
}
