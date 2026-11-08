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
            'SELECT m.label AS event_mode, COUNT(*) AS count
             FROM events e
             JOIN event_modes m ON m.id = e.event_mode_id
             GROUP BY m.label
             ORDER BY count DESC'
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

    /**
     * Period filter on the event start date. $from/$to are Y-m-d or null (open ended).
     * Returns [sql fragment starting with " AND ", bound params].
     */
    private function rangeClause(?string $from, ?string $to): array
    {
        $sql    = '';
        $params = [];

        if ($from !== null) {
            $sql .= ' AND e.start_date >= :rfrom';
            $params[':rfrom'] = $from;
        }

        if ($to !== null) {
            $sql .= ' AND e.start_date <= :rto';
            $params[':rto'] = $to;
        }

        return [$sql, $params];
    }

    private function rows(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** Headline numbers for the period. */
    public function periodSummary(?string $from, ?string $to): array
    {
        [$r, $p] = $this->rangeClause($from, $to);

        $events = (int) $this->rows("SELECT COUNT(*) AS n FROM events e WHERE TRUE{$r}", $p)[0]['n'];

        $people = (int) $this->rows(
            "SELECT COUNT(DISTINCT reg.reg_no) AS n
             FROM attendance a
             JOIN registrations reg ON reg.id = a.registration_id
             JOIN events e ON e.id = reg.event_id
             WHERE TRUE{$r}",
            $p
        )[0]['n'];

        $points = (float) $this->rows(
            "SELECT COALESCE(SUM(pl.points), 0) AS n
             FROM points_ledger pl
             JOIN events e ON e.id = pl.event_id
             WHERE TRUE{$r}",
            $p
        )[0]['n'];

        return ['events' => $events, 'practitioners' => $people, 'points' => $points];
    }

    /** On-site vs virtual, keyed by attendance method. */
    public function methodComparison(?string $from, ?string $to): array
    {
        [$r, $p] = $this->rangeClause($from, $to);

        $out = [];
        foreach (['onsite', 'virtual'] as $m) {
            $out[$m] = ['practitioners' => 0, 'signins' => 0, 'flagged' => 0, 'points' => 0.0];
        }

        foreach ($this->rows(
            "SELECT a.method::text AS method,
                    COUNT(DISTINCT reg.reg_no) AS practitioners,
                    COUNT(*) AS signins,
                    COUNT(*) FILTER (WHERE a.flagged) AS flagged
             FROM attendance a
             JOIN registrations reg ON reg.id = a.registration_id
             JOIN events e ON e.id = reg.event_id
             WHERE TRUE{$r}
             GROUP BY a.method",
            $p
        ) as $row) {
            $out[$row['method']]['practitioners'] = (int) $row['practitioners'];
            $out[$row['method']]['signins']       = (int) $row['signins'];
            $out[$row['method']]['flagged']       = (int) $row['flagged'];
        }

        foreach ($this->rows(
            "SELECT a.method::text AS method, COALESCE(SUM(pl.points), 0) AS points
             FROM points_ledger pl
             JOIN attendance a ON a.id = pl.attendance_id
             JOIN registrations reg ON reg.id = a.registration_id
             JOIN events e ON e.id = reg.event_id
             WHERE pl.kind = 'attendance'{$r}
             GROUP BY a.method",
            $p
        ) as $row) {
            $out[$row['method']]['points'] = (float) $row['points'];
        }

        return $out;
    }

    /** Share of attendees who passed the session quiz (virtual events). */
    public function quizPassRate(?string $from, ?string $to): array
    {
        [$r, $p] = $this->rangeClause($from, $to);

        $row = $this->rows(
            "SELECT COUNT(DISTINCT (q.registration_id, q.session_id)) AS attempted,
                    COUNT(DISTINCT (q.registration_id, q.session_id)) FILTER (WHERE q.passed) AS passed
             FROM quiz_attempts q
             JOIN registrations reg ON reg.id = q.registration_id
             JOIN events e ON e.id = reg.event_id
             WHERE TRUE{$r}",
            $p
        )[0];

        return ['attempted' => (int) $row['attempted'], 'passed' => (int) $row['passed']];
    }

    /** Registered vs attended per event type, completed events only. */
    public function showUpByMode(?string $from, ?string $to): array
    {
        [$r, $p] = $this->rangeClause($from, $to);

        return $this->rows(
            "SELECT m.label AS mode,
                    COUNT(reg.id) AS registered,
                    COUNT(reg.id) FILTER (WHERE EXISTS (SELECT 1 FROM attendance a WHERE a.registration_id = reg.id)) AS attended
             FROM registrations reg
             JOIN events e ON e.id = reg.event_id
             JOIN event_modes m ON m.id = e.event_mode_id
             WHERE e.end_date < CURRENT_DATE{$r}
             GROUP BY m.label, m.sort_order
             ORDER BY m.sort_order, m.label",
            $p
        );
    }

    /** Distinct practitioners per month and method. */
    public function monthlyByMethod(?string $from, ?string $to): array
    {
        [$r, $p] = $this->rangeClause($from, $to);

        return $this->rows(
            "SELECT to_char(date_trunc('month', e.start_date), 'Mon YYYY') AS month,
                    date_trunc('month', e.start_date) AS month_sort,
                    a.method::text AS method,
                    COUNT(DISTINCT reg.reg_no) AS people
             FROM attendance a
             JOIN registrations reg ON reg.id = a.registration_id
             JOIN events e ON e.id = reg.event_id
             WHERE TRUE{$r}
             GROUP BY month, month_sort, a.method
             ORDER BY month_sort",
            $p
        );
    }

    /** Distinct practitioners who attended, by cadre and method. */
    public function attendeesByCadre(?string $from, ?string $to): array
    {
        [$r, $p] = $this->rangeClause($from, $to);

        return $this->rows(
            "SELECT COALESCE(pt.cadre::text, 'other') AS cadre,
                    a.method::text AS method,
                    COUNT(DISTINCT reg.reg_no) AS people
             FROM attendance a
             JOIN registrations reg ON reg.id = a.registration_id
             JOIN participants pt ON pt.reg_no = reg.reg_no
             JOIN events e ON e.id = reg.event_id
             WHERE TRUE{$r}
             GROUP BY 1, a.method",
            $p
        );
    }

    public function eventsByModeInRange(?string $from, ?string $to): array
    {
        [$r, $p] = $this->rangeClause($from, $to);

        return $this->rows(
            "SELECT m.label AS event_mode, COUNT(*) AS count
             FROM events e
             JOIN event_modes m ON m.id = e.event_mode_id
             WHERE TRUE{$r}
             GROUP BY m.label
             ORDER BY count DESC",
            $p
        );
    }

    public function eventsByMonth(?string $from, ?string $to): array
    {
        [$r, $p] = $this->rangeClause($from, $to);

        return $this->rows(
            "SELECT to_char(date_trunc('month', e.start_date), 'Mon YYYY') AS month,
                    date_trunc('month', e.start_date) AS month_sort,
                    COUNT(*) AS count
             FROM events e
             WHERE TRUE{$r}
             GROUP BY month, month_sort
             ORDER BY month_sort",
            $p
        );
    }

    /** Providers ranked by number of activities (past and upcoming) in the period. */
    public function topProviders(?string $from, ?string $to, int $limit = 5): array
    {
        [$r, $p] = $this->rangeClause($from, $to);
        $p[':lim'] = $limit;

        $stmt = $this->pdo->prepare(
            "WITH reach AS (
                 SELECT reg.event_id, COUNT(DISTINCT reg.reg_no) AS people
                 FROM registrations reg
                 JOIN attendance a ON a.registration_id = reg.id
                 GROUP BY reg.event_id
             ),
             pts AS (
                 SELECT event_id, SUM(points) AS points FROM points_ledger GROUP BY event_id
             )
             SELECT pr.name,
                    COUNT(*) AS activities,
                    COUNT(*) FILTER (WHERE e.start_date > CURRENT_DATE) AS upcoming,
                    COALESCE(SUM(reach.people), 0) AS attendees,
                    COALESCE(SUM(pts.points), 0) AS points
             FROM events e
             JOIN providers pr ON pr.id = e.provider_id
             LEFT JOIN reach ON reach.event_id = e.id
             LEFT JOIN pts ON pts.event_id = e.id
             WHERE TRUE{$r}
             GROUP BY pr.id, pr.name
             ORDER BY activities DESC, upcoming DESC, pr.name
             LIMIT :lim"
        );

        foreach ($p as $k => $v) {
            $stmt->bindValue($k, $v, $k === ':lim' ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function eventsWithoutProvider(?string $from, ?string $to): int
    {
        [$r, $p] = $this->rangeClause($from, $to);

        return (int) $this->rows("SELECT COUNT(*) AS n FROM events e WHERE e.provider_id IS NULL{$r}", $p)[0]['n'];
    }

    public function flaggedByEventInRange(?string $from, ?string $to, int $limit = 5): array
    {
        [$r, $p] = $this->rangeClause($from, $to);
        $p[':lim'] = $limit;

        $stmt = $this->pdo->prepare(
            "SELECT e.title, COUNT(a.id) AS flagged_count
             FROM attendance a
             JOIN registrations reg ON reg.id = a.registration_id
             JOIN events e ON e.id = reg.event_id
             WHERE a.flagged = true{$r}
             GROUP BY e.id, e.title
             ORDER BY flagged_count DESC
             LIMIT :lim"
        );
        foreach ($p as $k => $v) {
            $stmt->bindValue($k, $v, $k === ':lim' ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
