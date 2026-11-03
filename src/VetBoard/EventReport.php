<?php

declare(strict_types=1);

namespace VetBoard;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use PDO;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

final class EventReport
{
    private const MAX_RANGE_DAYS = 14;

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getPdo();
    }

    public function programme(int $eventId): array
    {
        $presentations = (new SessionPresentation($this->pdo))->forEvent($eventId);
        $out           = [];

        foreach ((new Event())->getSessions($eventId) as $s) {
            $items = array_map(static fn(array $p): array => [
                'presenter' => (string) $p['presenter'],
                'topic'     => (string) $p['topic'],
                'start'     => substr((string) $p['start_time'], 0, 5),
                'end'       => substr((string) $p['end_time'], 0, 5),
            ], $presentations[(int) $s['id']] ?? []);

            $out[] = [
                'label' => (string) $s['label'],
                'date'  => $s['session_date'] ?: null,
                'start' => substr((string) $s['start_time'], 0, 5),
                'end'   => substr((string) $s['end_time'], 0, 5),
                'items' => $items,
            ];
        }

        usort($out, static fn(array $a, array $b): int =>
            strcmp(($a['date'] ?? '') . $a['start'], ($b['date'] ?? '') . $b['start']));

        return $out;
    }

    public function cadreByDay(int $eventId, array $cadreLabels, string $startDate, string $endDate): array
    {
        $perDay = $this->pdo->prepare(<<<'SQL'
            SELECT a.attendance_date::text AS day, COALESCE(p.cadre::text, '') AS cadre,
                   COUNT(DISTINCT r.reg_no) AS total
            FROM attendance a
            JOIN registrations r ON r.id = a.registration_id
            JOIN participants p  ON p.reg_no = r.reg_no
            WHERE r.event_id = :event AND NOT a.flagged AND NOT a.points_denied
            GROUP BY 1, 2
            SQL);
        $perDay->execute([':event' => $eventId]);

        $counts   = [];
        $attended = [];

        foreach ($perDay->fetchAll() as $row) {
            $counts[$row['cadre']][$row['day']] = (int) $row['total'];
            $attended[]                         = $row['day'];
        }

        $overall = $this->pdo->prepare(<<<'SQL'
            SELECT COALESCE(p.cadre::text, '') AS cadre, COUNT(DISTINCT r.reg_no) AS total
            FROM attendance a
            JOIN registrations r ON r.id = a.registration_id
            JOIN participants p  ON p.reg_no = r.reg_no
            WHERE r.event_id = :event AND NOT a.flagged AND NOT a.points_denied
            GROUP BY 1
            SQL);
        $overall->execute([':event' => $eventId]);

        $unique = [];

        foreach ($overall->fetchAll() as $row) {
            $unique[$row['cadre']] = (int) $row['total'];
        }

        $excluded = $this->pdo->prepare(<<<'SQL'
            SELECT COUNT(*)
            FROM attendance a
            JOIN registrations r ON r.id = a.registration_id
            WHERE r.event_id = :event AND (a.flagged OR a.points_denied)
            SQL);
        $excluded->execute([':event' => $eventId]);

        $keys = array_keys($cadreLabels);

        foreach (array_keys($unique) as $key) {
            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        $days   = $this->days($startDate, $endDate, $attended);
        $totals = array_fill_keys($days, 0);
        $rows   = [];

        foreach ($keys as $key) {
            $perCadre = [];

            foreach ($days as $day) {
                $n              = $counts[$key][$day] ?? 0;
                $perCadre[$day] = $n;
                $totals[$day]  += $n;
            }

            $rows[] = [
                'label' => $cadreLabels[$key] ?? ($key === '' ? 'Unspecified' : (string) $key),
                'days'  => $perCadre,
                'total' => $unique[$key] ?? 0,
            ];
        }

        return [
            'days'     => $days,
            'rows'     => $rows,
            'totals'   => ['days' => $totals, 'total' => array_sum(array_column($rows, 'total'))],
            'excluded' => (int) $excluded->fetchColumn(),
        ];
    }

    public static function appendSheets(Spreadsheet $workbook, array $programme, array $stats): void
    {
        $sheet = $workbook->createSheet();
        $sheet->setTitle('Programme');
        $sheet->fromArray(['Day', 'Session', 'Session time', 'Presenter', 'Topic', 'Presentation time'], null, 'A1');

        $r = 2;

        foreach ($programme as $s) {
            $day = $s['date'] ? date('d M Y', strtotime($s['date'])) : 'All days';

            foreach ($s['items'] ?: [null] as $p) {
                $sheet->fromArray([
                    $day,
                    Security::excelSafe($s['label']),
                    $s['start'] . ' - ' . $s['end'],
                    $p ? Security::excelSafe($p['presenter']) : '-',
                    $p ? Security::excelSafe($p['topic']) : '-',
                    $p ? $p['start'] . ' - ' . $p['end'] : '-',
                ], null, 'A' . $r++);
            }
        }

        self::finishSheet($sheet, 'A1:F1');

        $summary = $workbook->createSheet();
        $summary->setTitle('Cadre Summary');

        $header = array_merge(
            ['Cadre'],
            array_map(static fn(string $d): string => date('D d M', strtotime($d)), $stats['days']),
            ['Total']
        );
        $summary->fromArray($header, null, 'A1');

        $r = 2;

        foreach ($stats['rows'] as $row) {
            $summary->fromArray(
                array_merge([Security::excelSafe($row['label'])], array_values($row['days']), [$row['total']]),
                null,
                'A' . $r++
            );
        }

        $summary->fromArray(
            array_merge(['Total'], array_values($stats['totals']['days']), [$stats['totals']['total']]),
            null,
            'A' . $r
        );
        $summary->getStyle('A' . $r . ':' . $summary->getHighestColumn() . $r)->getFont()->setBold(true);

        $note = 'Unique attendees per day; Total counts each person once.';

        if ($stats['excluded'] > 0) {
            $note .= ' ' . $stats['excluded'] . ' record(s) pending review or denied are excluded.';
        }

        $summary->setCellValue('A' . ($r + 2), $note);

        self::finishSheet($summary, 'A1:' . $summary->getHighestColumn() . '1');
    }

    private static function finishSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $headerRange): void
    {
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFill()->setFillType('solid')->getStartColor()->setRGB('D9D9D9');

        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    private function days(string $startDate, string $endDate, array $attended): array
    {
        $days = $attended;

        try {
            $start = new DateTimeImmutable($startDate);
            $end   = new DateTimeImmutable($endDate);

            if ($start <= $end && $start->diff($end)->days < self::MAX_RANGE_DAYS) {
                foreach (new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day')) as $d) {
                    $days[] = $d->format('Y-m-d');
                }
            }
        } catch (\Exception) {
        }

        $days = array_values(array_unique($days));
        sort($days);

        return $days;
    }
}
