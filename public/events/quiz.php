<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Event;
use VetBoard\EventQuiz;
use VetBoard\QuizAttempt;
use VetBoard\Registration;

Auth::startSession();

$token   = trim($_GET['token'] ?? '');
$session = trim($_GET['session'] ?? '');

if (empty($token) || empty($session)) {
    http_response_code(400);
    die('Invalid quiz link.');
}

$context = $_SESSION['quiz_context'] ?? null;

if (!$context || $context['token'] !== $token || $context['session'] !== $session) {
    $_SESSION['attend_error'] = 'Sign in for this session before taking the quiz.';
    header('Location: /events/attend.php?token=' . urlencode($token));
    exit;
}

$eventModel = new Event();
$event      = $eventModel->findByToken($token);

if (!$event) {
    http_response_code(404);
    die('Event not found or link is invalid.');
}

$quizModel          = new EventQuiz();
$attemptModel       = new QuizAttempt();
$registrationModel  = new Registration();
$registrationId  = (int) $context['registration_id'];
$eventId         = (int) $context['event_id'];
$sessionWindowId = (int) $session;
$sessionLabels   = array_column($eventModel->getSessions($eventId), 'label', 'id');

if ($sessionWindowId === null || !$quizModel->hasQuiz($eventId, $sessionWindowId)) {
    unset($_SESSION['quiz_context']);
    $_SESSION['attend_success'] = true;
    $_SESSION['attend_session'] = $session;
    $_SESSION['attend_flagged'] = false;
    header('Location: /events/attend.php?token=' . urlencode($token) . '&done=1');
    exit;
}

$error  = $_SESSION['quiz_error']  ?? null;
$result = $_SESSION['quiz_result'] ?? null;
unset($_SESSION['quiz_error'], $_SESSION['quiz_result']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    $answers = [];
    foreach (($_POST['answers'] ?? []) as $questionId => $optionId) {
        $answers[(int) $questionId] = (int) $optionId;
    }

    try {
        $result = $attemptModel->submit($registrationId, $sessionWindowId, $eventId, $answers);

        if ($result['passed']) {
            $registrationModel->maybeAwardPoints($registrationId, $sessionWindowId);
        }

        $_SESSION['quiz_result'] = $result;
        header('Location: /events/quiz.php?token=' . urlencode($token) . '&session=' . urlencode($session));
        exit;

    } catch (\InvalidArgumentException $e) {
        $_SESSION['quiz_error'] = $e->getMessage();
        header('Location: /events/quiz.php?token=' . urlencode($token) . '&session=' . urlencode($session));
        exit;
    }
}

$alreadyPassed      = $attemptModel->hasPassed($registrationId, $sessionWindowId);
$attemptsUsed       = $attemptModel->attemptCount($registrationId, $sessionWindowId);
$attemptsRemaining  = max(0, QuizAttempt::MAX_ATTEMPTS - $attemptsUsed);
$exhausted          = !$alreadyPassed && $attemptsRemaining <= 0;

$questions = [];
if (!$alreadyPassed && !$exhausted) {
    $questions = $quizModel->getQuestions($eventId, $sessionWindowId, false);
    shuffle($questions);
    foreach ($questions as &$q) {
        shuffle($q['options']);
    }
    unset($q);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Session Quiz - <?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
.quiz-question { margin-bottom: 1.4rem; }
.quiz-question p.q-text { font-weight: 600; margin-bottom: .5rem; }
.quiz-option {
    display: flex; align-items: center; gap: .5rem;
    padding: .6rem .75rem; border: 1.5px solid #d8dde3; border-radius: 10px;
    margin-bottom: .4rem; cursor: pointer; font-size: .9rem; transition: var(--transition);
}
.quiz-option:hover { border-color: var(--kvb-green); }
.quiz-option input[type="radio"] { accent-color: var(--kvb-green-dark); width: 16px; height: 16px; flex-shrink: 0; }
.score-big { font-size: 2.4rem; font-weight: 700; margin: .5rem 0; }
.score-pass { color: var(--kvb-green-dark); }
.score-fail { color: var(--kvb-red-dark); }
</style>
</head>
<body>
<div class="public-page">
<div class="public-shell">
    <div class="public-header">
        <h1><?= htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8') ?></h1>
        <p>Session Quiz &mdash; <?= htmlspecialchars($sessionLabels[$session] ?? $session, ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <?php if ($alreadyPassed): ?>
        <div class="card" style="text-align:center;padding:2rem;">
            <div style="font-size:3rem;">&#10003;</div>
            <h2 style="color:var(--kvb-green-dark);margin:.5rem 0;">Quiz Passed</h2>
            <p>Attendance confirmed. Points recorded.</p>
        </div>

    <?php elseif ($result): ?>
        <div class="card" style="text-align:center;padding:2rem;">
            <div class="score-big <?= $result['passed'] ? 'score-pass' : 'score-fail' ?>">
                <?= number_format($result['score_percent'], 0) ?>%
            </div>
            <?php if ($result['passed']): ?>
                <h2 style="color:var(--kvb-green-dark);">Passed</h2>
                <p>Attendance confirmed. Points recorded.</p>
            <?php elseif ($result['attempts_remaining'] > 0): ?>
                <h2 style="color:var(--kvb-red-dark);">Not Quite &mdash; 90% Required</h2>
                <p>You have <?= $result['attempts_remaining'] ?> attempt<?= $result['attempts_remaining'] === 1 ? '' : 's' ?> remaining.</p>
                <a href="/events/quiz.php?token=<?= urlencode($token) ?>&session=<?= urlencode($session) ?>" class="btn btn-primary" style="margin-top:1rem;display:inline-block;">Try Again</a>
            <?php else: ?>
                <h2 style="color:var(--kvb-red-dark);">No Attempts Remaining</h2>
                <p>You did not reach 90% and have no attempts left. Speak to the supervisor.</p>
            <?php endif; ?>
        </div>

    <?php elseif ($exhausted): ?>
        <div class="card" style="text-align:center;padding:2rem;">
            <h2 style="color:var(--kvb-red-dark);">No Attempts Remaining</h2>
            <p>You did not reach 90% and have no attempts left. Speak to the supervisor.</p>
        </div>

    <?php else: ?>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="card">
            <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:1.25rem;">
                Answer all questions. You need at least 90% correct to confirm your points for this session.
                Attempt <?= $attemptsUsed + 1 ?> of <?= QuizAttempt::MAX_ATTEMPTS ?>.
            </p>

            <form method="POST" action="/events/quiz.php?token=<?= urlencode($token) ?>&session=<?= urlencode($session) ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">

                <?php foreach ($questions as $i => $q): ?>
                    <div class="quiz-question">
                        <p class="q-text"><?= $i + 1 ?>. <?= htmlspecialchars($q['question_text'], ENT_QUOTES, 'UTF-8') ?></p>
                        <?php foreach ($q['options'] as $opt): ?>
                            <label class="quiz-option">
                                <input type="radio" name="answers[<?= (int) $q['id'] ?>]" value="<?= (int) $opt['id'] ?>" required>
                                <?= htmlspecialchars($opt['option_text'], ENT_QUOTES, 'UTF-8') ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

                <button type="submit" class="btn btn-primary btn-block" style="margin-top:.5rem;">Submit Answers</button>
            </form>
        </div>

    <?php endif; ?>
</div>
</div>
</body>
</html>
