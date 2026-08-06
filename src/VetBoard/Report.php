<?php

declare(strict_types=1);

namespace VetBoard;

use PDO;

class Report
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getPdo();
    }

    public function totals(): array
    {
        $totalEvents = (int) $this->pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();

        $totalParticipants = (int) $this->pdo->query('SELECT COUNT(*) FROM participants')->fetchColumn();

        $totalAttendance = (int) $this->pdo->query('SELECT COUNT(*) FROM attendance')->fetchColumn();

        $flaggedAttendance = (int) $this->pdo->query('SELECT COUNT(*) FROM attendance WHERE flagged = true')->fetchColumn();

        return [
            'total_events'       => $totalEvents,
            'total_participants' => $totalParticipants,
            'total_attendance'   => $totalAttendance,
            'flagged_attendance' => $flaggedAttendance,
        ];
    }

    public function eventsByMode(): array
    {
        $stmt = $this->pdo->query(
            'SELECT event_mode, COUNT(*) AS count FROM events GROUP BY event_mode ORDER BY count DESC'
        );

        return $stmt->fetchAll();
    }

    public function participantsByCadre(): array
    {
        $stmt = $this->pdo->query(
            'SELECT cadre, COUNT(*) AS count FROM participants GROUP BY cadre ORDER BY count DESC'
        );

        return $stmt->fetchAll();
    }

    public function monthlyEventsTrend(int $months = 6): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT to_char(date_trunc('month', start_date), 'Mon YYYY') AS month,
                    date_trunc('month', start_date) AS month_sort,
                    COUNT(*) AS count
             FROM events
             WHERE start_date >= (CURRENT_DATE - (:months || ' months')::interval)
             GROUP BY month, month_sort
             ORDER BY month_sort ASC"
        );
        $stmt->execute([':months' => $months]);

        return $stmt->fetchAll();
    }

    public function flaggedByEvent(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.title, COUNT(a.id) AS flagged_count
             FROM attendance a
             JOIN registrations r ON r.id = a.registration_id
             JOIN events e ON e.id = r.event_id
             WHERE a.flagged = true
             GROUP BY e.title
             ORDER BY flagged_count DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
