<?php

declare(strict_types=1);

namespace VetBoard;

use InvalidArgumentException;
use PDO;

final class SessionPresentation
{
    private PDO $pdo;

    /** @var array<string, bool> */
    private array $known = [];

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getPdo();
    }

    /** @return array<int, list<array>> Presentations keyed by session id. */
    public function forEvent(int $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sp.id, sp.session_id, sp.presenter, sp.reg_no, sp.topic, sp.start_time, sp.end_time
             FROM session_presentations sp
             JOIN event_sessions s ON s.id = sp.session_id
             WHERE s.event_id = :event
             ORDER BY sp.session_id, sp.start_time, sp.id'
        );
        $stmt->execute([':event' => $eventId]);

        $bySession = [];

        foreach ($stmt->fetchAll() as $row) {
            $bySession[(int) $row['session_id']][] = $row;
        }

        return $bySession;
    }

    // Replaces the presentations of each submitted session. Runs inside the caller's transaction.
    public function save(array $posted, array $sessionIdMap, array $sessions): void
    {
        foreach ($sessionIdMap as $clientId => $sessionId) {
            $rows = $posted[$clientId] ?? null;

            if (!is_array($rows)) {
                continue;
            }

            $window = $sessions[$clientId] ?? [];

            $clean = $this->clean(
                $rows,
                (string) ($window['label'] ?? ''),
                $this->time($window['start_time'] ?? ''),
                $this->time($window['end_time'] ?? '')
            );

            $this->replace((int) $sessionId, $clean);
        }
    }

    private function clean(array $rows, string $label, ?string $sessionStart, ?string $sessionEnd): array
    {
        $out   = [];
        $where = 'Session "' . $label . '": ';

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $presenter = trim((string) ($row['presenter'] ?? ''));
            $topic     = trim((string) ($row['topic'] ?? ''));
            $regNo     = strtoupper(trim((string) ($row['reg_no'] ?? '')));
            $rawStart  = trim((string) ($row['start_time'] ?? ''));
            $rawEnd    = trim((string) ($row['end_time'] ?? ''));

            if ($presenter === '' && $topic === '' && $regNo === '' && $rawStart === '' && $rawEnd === '') {
                continue;
            }

            $start = $this->time($rawStart);
            $end   = $this->time($rawEnd);

            if ($presenter === '' || $topic === '' || $start === null || $end === null) {
                throw new InvalidArgumentException($where . 'each presentation needs a presenter, topic, start time and end time.');
            }

            if (mb_strlen($presenter) > 150 || mb_strlen($topic) > 255) {
                throw new InvalidArgumentException($where . 'presenter is limited to 150 characters and topic to 255.');
            }

            if ($regNo !== '' && !$this->participantExists($regNo)) {
                throw new InvalidArgumentException($where . "registration number {$regNo} was not found.");
            }

            if ($end <= $start) {
                throw new InvalidArgumentException($where . 'a presentation must end after it starts.');
            }

            if ($sessionStart !== null && $sessionEnd !== null && ($start < $sessionStart || $end > $sessionEnd)) {
                throw new InvalidArgumentException($where . "presentations must fall within the session time ({$sessionStart} to {$sessionEnd}).");
            }

            $out[] = [
                'presenter' => $presenter,
                'reg_no'    => $regNo !== '' ? $regNo : null,
                'topic'     => $topic,
                'start'     => $start,
                'end'       => $end,
            ];
        }

        usort($out, static fn(array $a, array $b): int => strcmp($a['start'], $b['start']));

        for ($i = 1; $i < count($out); $i++) {
            if ($out[$i]['start'] < $out[$i - 1]['end']) {
                throw new InvalidArgumentException($where . 'presentations must not overlap.');
            }
        }

        return $out;
    }

    private function replace(int $sessionId, array $rows): void
    {
        $this->pdo
            ->prepare('DELETE FROM session_presentations WHERE session_id = :session')
            ->execute([':session' => $sessionId]);

        $insert = $this->pdo->prepare(
            'INSERT INTO session_presentations (session_id, presenter, reg_no, topic, start_time, end_time, sort_order)
             VALUES (:session, :presenter, :reg, :topic, :start, :end, :sort)'
        );

        foreach ($rows as $i => $row) {
            $insert->execute([
                ':session'   => $sessionId,
                ':presenter' => $row['presenter'],
                ':reg'       => $row['reg_no'],
                ':topic'     => $row['topic'],
                ':start'     => $row['start'],
                ':end'       => $row['end'],
                ':sort'      => $i,
            ]);
        }
    }

    private function participantExists(string $regNo): bool
    {
        if (!isset($this->known[$regNo])) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM participants WHERE reg_no = :reg');
            $stmt->execute([':reg' => $regNo]);
            $this->known[$regNo] = (bool) $stmt->fetchColumn();
        }

        return $this->known[$regNo];
    }

    private function time(mixed $value): ?string
    {
        $v = trim((string) $value);

        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $v) ? substr($v, 0, 5) : null;
    }
}
