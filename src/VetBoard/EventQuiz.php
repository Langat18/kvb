<?php

declare(strict_types=1);

namespace VetBoard;

use PDO;

class EventQuiz
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getPdo();
    }

    /**
     * Resolves a session_windows.id from its item_key (e.g. 'morning').
     * Used by pages that only have the session key on hand (attendance
     * check-in flows) and need the numeric id for quiz lookups.
     */
    public function resolveSessionWindowId(string $itemKey): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM session_windows WHERE item_key = :item_key');
        $stmt->execute([':item_key' => $itemKey]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    public function hasQuiz(int $eventId, int $sessionWindowId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT EXISTS(SELECT 1 FROM event_quiz_questions WHERE event_id = :event_id AND session_window_id = :swid)'
        );
        $stmt->execute([':event_id' => $eventId, ':swid' => $sessionWindowId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Returns questions with options for an event+session, in display order.
     * $includeCorrectness must stay false for anything participant-facing —
     * true is only for the supervisor authoring screen and internal grading.
     */
    public function getQuestions(int $eventId, int $sessionWindowId, bool $includeCorrectness = false): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, question_text, sort_order
             FROM event_quiz_questions
             WHERE event_id = :event_id AND session_window_id = :swid
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([':event_id' => $eventId, ':swid' => $sessionWindowId]);
        $questions = $stmt->fetchAll();

        foreach ($questions as &$q) {
            $optStmt = $this->pdo->prepare(
                'SELECT id, option_text' . ($includeCorrectness ? ', is_correct' : '') . '
                 FROM event_quiz_options
                 WHERE question_id = :question_id
                 ORDER BY sort_order ASC, id ASC'
            );
            $optStmt->execute([':question_id' => $q['id']]);
            $q['options'] = $optStmt->fetchAll();
        }
        unset($q);

        return $questions;
    }

    /**
     * Replaces all questions (and options) for an event+session in one
     * transaction. Expects:
     * [ ['text' => '...', 'options' => [['text' => '...', 'correct' => bool], ...]], ... ]
     * Throws if any question has zero options marked correct.
     */
    public function saveQuestions(int $eventId, int $sessionWindowId, array $questions): void
    {
        $this->pdo->beginTransaction();

        try {
            $existing = $this->pdo->prepare(
                'SELECT id FROM event_quiz_questions WHERE event_id = :event_id AND session_window_id = :swid'
            );
            $existing->execute([':event_id' => $eventId, ':swid' => $sessionWindowId]);
            $existingIds = array_column($existing->fetchAll(), 'id');

            if (!empty($existingIds)) {
                $placeholders = implode(',', array_fill(0, count($existingIds), '?'));
                $del = $this->pdo->prepare("DELETE FROM event_quiz_questions WHERE id IN ({$placeholders})");
                $del->execute($existingIds);
            }

            $qStmt = $this->pdo->prepare(
                'INSERT INTO event_quiz_questions (event_id, session_window_id, question_text, sort_order)
                 VALUES (:event_id, :swid, :question_text, :sort_order) RETURNING id'
            );
            $oStmt = $this->pdo->prepare(
                'INSERT INTO event_quiz_options (question_id, option_text, is_correct, sort_order)
                 VALUES (:question_id, :option_text, :is_correct, :sort_order)'
            );

            foreach (array_values($questions) as $qIndex => $question) {
                $text = trim($question['text'] ?? '');

                if ($text === '') {
                    continue;
                }

                $qStmt->execute([
                    ':event_id'      => $eventId,
                    ':swid'          => $sessionWindowId,
                    ':question_text' => $text,
                    ':sort_order'    => $qIndex,
                ]);
                $questionId = (int) $qStmt->fetchColumn();

                $hasCorrect = false;

                foreach (array_values($question['options'] ?? []) as $oIndex => $option) {
                    $optText = trim($option['text'] ?? '');

                    if ($optText === '') {
                        continue;
                    }

                    $isCorrect  = !empty($option['correct']);
                    $hasCorrect = $hasCorrect || $isCorrect;

                    $oStmt->bindValue(':question_id', $questionId, PDO::PARAM_INT);
                    $oStmt->bindValue(':option_text', $optText, PDO::PARAM_STR);
                    $oStmt->bindValue(':is_correct', $isCorrect, PDO::PARAM_BOOL);
                    $oStmt->bindValue(':sort_order', $oIndex, PDO::PARAM_INT);
                    $oStmt->execute();
                }

                if (!$hasCorrect) {
                    throw new \InvalidArgumentException('Every question needs at least one option marked correct.');
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
