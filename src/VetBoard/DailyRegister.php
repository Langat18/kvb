<?php

declare(strict_types=1);

namespace VetBoard;

final class DailyRegister
{
    
    public static function build(array $rows, array $sessions, string $date): array
    {
        $used = [];

        foreach ($rows as $r) {
            $used[(int) ($r['session_id'] ?? 0)] = true;
        }

        $columns = [];

        foreach ($sessions as $s) {
            $id    = (int) $s['id'];
            $onDay = empty($s['session_date']) || $s['session_date'] === $date;

            if ((self::truthy($s['active'] ?? true) && $onDay) || isset($used[$id])) {
                $columns[] = ['id' => $id, 'label' => (string) $s['label']];
            }
        }

        if (isset($used[0])) {
            $columns[] = ['id' => 0, 'label' => 'Other'];
        }

        $people = [];

        foreach ($rows as $r) {
            $reg = (string) $r['reg_no'];

            $people[$reg] ??= [
                'reg_no'  => $reg,
                'name'    => (string) $r['name'],
                'cadre'   => (string) ($r['cadre'] ?? ''),
                'email'   => (string) ($r['email'] ?? ''),
                'phone'   => (string) ($r['phone'] ?? ''),
                'cells'   => [],
                'methods' => [],
                'total'   => 0,
            ];

            $sid    = (int) ($r['session_id'] ?? 0);
            $points = (float) ($r['points_awarded'] ?? 0);
            $state  = self::truthy($r['points_denied'] ?? false)
                ? 'denied'
                : (self::truthy($r['flagged'] ?? false) ? 'flagged' : 'ok');

            $people[$reg]['cells'][$sid] = [
                'state'  => $state,
                'points' => round(($people[$reg]['cells'][$sid]['points'] ?? 0) + $points, 1),
            ];
            $people[$reg]['methods'][ucfirst((string) $r['method'])] = true;
            $people[$reg]['total'] = round($people[$reg]['total'] + $points, 1);
        }

        foreach ($people as &$p) {
            $p['method'] = implode(', ', array_keys($p['methods']));
            unset($p['methods']);
        }
        unset($p);

        return ['sessions' => $columns, 'people' => array_values($people)];
    }

    public static function cellText(?array $cell): string
    {
        return match (true) {
            $cell === null              => '—',
            $cell['state'] === 'denied'  => '✗ Denied',
            $cell['state'] === 'flagged' => '⚠ Pending',
            default                     => Points::format($cell['points']),
        };
    }

    public static function cellValue(?array $cell): float|string
    {
        return match (true) {
            $cell === null              => '-',
            $cell['state'] === 'denied'  => 'Denied',
            $cell['state'] === 'flagged' => 'Pending review',
            default                     => (float) $cell['points'],
        };
    }

    public static function cellClass(?array $cell): string
    {
        return match (true) {
            $cell === null                                   => 'mark-none',
            in_array($cell['state'], ['denied', 'flagged'], true) => 'check-flag',
            default                                          => '',
        };
    }

    public static function status(array $row): string
    {
        if (self::truthy($row['points_denied'] ?? false)) {
            return 'Denied';
        }

        if (self::truthy($row['flagged'] ?? false)) {
            return 'Pending review';
        }

        return (float) ($row['points_awarded'] ?? 0) > 0 ? 'Awarded' : 'No points';
    }

    public static function truthy(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 1 || $value === '1';
    }
}
