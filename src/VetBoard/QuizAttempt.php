<?php

declare(strict_types=1);

namespace VetBoard;

use PDO;

class QuizAttempt
{
    public const MAX_ATTEMPTS   = 2;
    public const PASS_THRESHOLD = 90.0;

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getPdo();
    }

    public function attemptCount(int $registrationId, int $sessionWindowId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM quiz_attempts WHERE registration_id = :rid AND session_window_id = :swid'
        );
        $stmt->execute([':rid' => $registrationId, ':swid' => $sessionWindowId]);

        return (int) $stmt->fetchColumn();
    }

    public function hasPassed(int $registrationId, int $sessionWindowId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT EXISTS(SELECT 1 FROM quiz_attempts WHERE registration_id = :rid AND session_window_id = :swid AND passed = TRUE)'
        );
        $stmt->execute([':rid' => $registrationId, ':swid' => $sessionWindowId]);

        return (bool) $stmt->fetchColumn();
    }

    public function bestScore(int $registrationId, int $sessionWindowId): ?float
    {
        $stmt = $this->pdo->prepare(
            'SELECT MAX(score_percent) FROM quiz_attempts WHERE registration_id = :rid AND session_window_id = :swid'
        );
        $stmt->execute([':rid' => $registrationId, ':swid' => $sessionWindowId]);
        $val = $stmt->fetchColumn();

        return $val !== null ? (float) $val : null;
    }

    /**
     * Grades and records one attempt. $answers is [question_id => option_id].
     * Returns attempt_number, score_percent, passed, attempts_remaining.
     * Throws if already passed or attempts are exhausted.
     */
    public function submit(int $registrationId, int $sessionWindowId, int $eventId, array $answers): array
    {
        if ($this->hasPassed($registrationId, $sessionWindowId)) {
            throw new \InvalidArgumentException('This quiz has already been passed.');
        }

        $attemptNumber = $this->attemptCount($registrationId, $sessionWindowId) + 1;

        if ($attemptNumber > self::MAX_ATTEMPTS) {
            throw new \InvalidArgumentException('No attempts remaining for this quiz.');
        }

        $quizModel = new EventQuiz();
        $questions = $quizModel->getQuestions($eventId, $sessionWindowId, true);

        if (empty($questions)) {
            throw new \InvalidArgumentException('No quiz is configured for this session.');
        }

        $correctCount = 0;

        foreach ($questions as $question) {
            $submittedOptionId = (int) ($answers[$question['id']] ?? 0);

            foreach ($question['options'] as $option) {
                if ((int) $option['id'] === $submittedOptionId && $option['is_correct']) {
                    $correctCount++;
                    break;
                }
            }
        }

        $scorePercent = round(($correctCount / count($questions)) * 100, 2);
        $passed       = $scorePercent >= self::PASS_THRESHOLD;

        $stmt = $this->pdo->prepare(
            'INSERT INTO quiz_attempts (registration_id, session_window_id, attempt_number, score_percent, passed, answers)
             VALUES (:rid, :swid, :attempt_number, :score, :passed, :answers)'
        );
        $stmt->bindValue(':rid', $registrationId, PDO::PARAM_INT);
        $stmt->bindValue(':swid', $sessionWindowId, PDO::PARAM_INT);
        $stmt->bindValue(':attempt_number', $attemptNumber, PDO::PARAM_INT);
        $stmt->bindValue(':score', $scorePercent);
        $stmt->bindValue(':passed', $passed, PDO::PARAM_BOOL);
        $stmt->bindValue(':answers', json_encode($answers));
        $stmt->execute();

        return [
            'attempt_number'     => $attemptNumber,
            'score_percent'      => $scorePercent,
            'passed'             => $passed,
            'attempts_remaining' => max(0, self::MAX_ATTEMPTS - $attemptNumber),
        ];
    }
}
