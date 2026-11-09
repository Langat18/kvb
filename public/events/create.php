<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../templates/icons.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\Event;
use VetBoard\EventModeRepository;
use VetBoard\EventQuiz;

Auth::startSession();
Auth::requirePermission('create_events');

$breadcrumbs = [['label' => 'Dashboard', 'url' => '/index.php'], ['label' => 'Events', 'url' => '/events/index.php'], ['label' => 'New Event', 'url' => null]];

$user = Auth::user();

$eventModeRepo    = new EventModeRepository();
$eventModes       = $eventModeRepo->list(true);

$error            = $_SESSION['error']            ?? null;
$duplicateWarning = $_SESSION['duplicate_warning'] ?? null;
$formData         = $_SESSION['form_data']         ?? [];
unset($_SESSION['error'], $_SESSION['duplicate_warning'], $_SESSION['form_data']);

require __DIR__ . '/../templates/sidebar_data.php';

// Parses quiz[] POST into [session_id => ['clear' => bool, 'questions' => [...]]].
// session_id may be a real numeric id or a client-side placeholder (new_N) for a session
// added in this same submission. Logs if the browser's pre-submit question count doesn't
// match what arrived server-side.
function parseQuizSubmission(array $post, int $eventId): array
{
    $result = [];

    foreach ($post as $swid => $swdata) {
        $swid = (string) $swid;

        $expectedCount = (int) ($swdata['expected_count'] ?? 0);
        $actualCount   = count($swdata['questions'] ?? []);

        if ($expectedCount !== $actualCount) {
            error_log("Quiz integrity mismatch — event {$eventId}, session_window {$swid}: expected {$expectedCount}, got {$actualCount}.");
        }

        if (!empty($swdata['clear'])) {
            $result[$swid] = ['clear' => true, 'questions' => []];
            continue;
        }

        $questions = [];

        foreach (($swdata['questions'] ?? []) as $qdata) {
            $text = trim($qdata['text'] ?? '');

            if ($text === '') {
                continue;
            }

            $options    = [];
            $correctIdx = (string) ($qdata['correct'] ?? '');

            foreach (($qdata['options'] ?? []) as $idx => $optText) {
                $optText = trim((string) $optText);

                if ($optText === '') {
                    continue;
                }

                $options[] = ['text' => $optText, 'correct' => ((string) $idx === $correctIdx)];
            }

            if (!empty($options)) {
                $questions[] = ['text' => $text, 'options' => $options];
            }
        }

        if (!empty($questions)) {
            $result[$swid] = ['clear' => false, 'questions' => $questions];
        }
    }

    return $result;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token. Please refresh and try again.');
    }

    $data = [
        'title'                     => $_POST['title']             ?? '',
        'event_code'                => $_POST['event_code']        ?? '',
        'event_mode_id'             => $_POST['event_mode_id']  !== '' ? (int) $_POST['event_mode_id'] : null,
        'venue'                     => $_POST['venue']              ?? '',
        'provider_id'               => $_POST['provider_id']    !== '' ? (int) $_POST['provider_id'] : null,
        'provider_accreditation_no' => $_POST['provider_accreditation_no'] ?? '',
        'coordinator'               => $_POST['coordinator']        ?? '',
        'start_date'                => $_POST['start_date']        ?? '',
        'end_date'                  => $_POST['end_date']           ?? '',
        'geo_lat'                   => $_POST['geo_lat']    !== '' ? (float) $_POST['geo_lat']    : null,
        'geo_lng'                   => $_POST['geo_lng']    !== '' ? (float) $_POST['geo_lng']    : null,
        'geo_radius_m'              => $_POST['geo_radius_m'] !== '' ? (int) $_POST['geo_radius_m'] : null,
        'meeting_provider'          => $_POST['meeting_provider'] ?? '',
        'meeting_link'              => $_POST['meeting_link']      ?? '',
        'day_confirmation'          => ($_POST['day_confirmation'] ?? '') === '1',
        'sessions'                  => $_POST['sessions'] ?? [],
        'presentations'             => $_POST['presentations'] ?? [],
        'created_by'                => $user['id'],
    ];

    $eventModel = new Event();

    if (empty($_POST['confirm_duplicate'])) {
        $similar = $eventModel->findRecentSimilarTitle($data['title']);

        if ($similar) {
            $_SESSION['duplicate_warning'] = $similar;
            $_SESSION['form_data']         = $data;
            header('Location: /events/create.php');
            exit;
        }
    }

    try {
        $created = $eventModel->create($data);
        $eventId = $created['id'];
        $sessionIdMap = $created['session_id_map'];
    } catch (\InvalidArgumentException $e) {
        $_SESSION['error']     = $e->getMessage();
        $_SESSION['form_data'] = $data;
        header('Location: /events/create.php');
        exit;
    } catch (\Throwable $e) {
        error_log('Event creation error: ' . $e->getMessage());
        $_SESSION['error']     = 'A system error occurred. Please try again.';
        $_SESSION['form_data'] = $data;
        header('Location: /events/create.php');
        exit;
    }

    $created = $eventModel->findById($eventId);

    try {
        // Quizzes only apply to virtual/hybrid — never save them for a pure onsite event.
        if (($created['event_mode'] ?? '') !== 'onsite') {
            $quizModel = new EventQuiz();

            foreach (parseQuizSubmission($_POST['quiz'] ?? [], $eventId) as $swid => $q) {
                $realSwid = $sessionIdMap[$swid] ?? null;
                if ($realSwid === null) {
                    continue;
                }
                $quizModel->saveQuestions($eventId, $realSwid, $q['questions']);
            }
        }

        $_SESSION['success'] = 'Event created successfully.';
        header('Location: /events/view.php?token=' . urlencode($created['view_token']));
        exit;

    } catch (\InvalidArgumentException $e) {
        $_SESSION['success'] = 'Event created, but a quiz could not be saved (' . $e->getMessage() . '). You can fix it below.';
        header('Location: /events/edit.php?token=' . urlencode($created['view_token']));
        exit;

    } catch (\Throwable $e) {
        error_log('Quiz save error: ' . $e->getMessage());
        $_SESSION['success'] = 'Event created, but the quiz could not be saved due to a system error. You can add it below.';
        header('Location: /events/edit.php?token=' . urlencode($created['view_token']));
        exit;
    }
}

$v = $formData;

$sessionsForDisplay = [];
foreach ((array) ($formData['sessions'] ?? []) as $clientId => $s) {
    $sessionsForDisplay[] = [
        'id'                   => $clientId,
        'label'                => $s['label'] ?? '',
        'start_time'           => $s['start_time'] ?? '',
        'end_time'             => $s['end_time'] ?? '',
        'points'               => (string) ($s['points'] ?? 0),
        'session_date'         => $s['session_date'] ?? '',
        'require_late_signin'  => !empty($s['require_late_signin']),
        'signin_lead_minutes'  => $s['signin_lead_minutes'] ?? '',
        'signin_grace_minutes' => $s['signin_grace_minutes'] ?? '',
    ];
}
$presentationsBySession = (array) ($formData['presentations'] ?? []);
$quizBySession          = [];
$formAction             = '/events/create.php';
$submitLabel            = 'Create event';
$cancelUrl              = '/events/index.php';
$providerNameDisplay    = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>New Event - Kenya Veterinary Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css?v=<?= (int) @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/style.css') ?>">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="page-with-sidebar">
    <div class="ef-page-head">
        <div>
            <h1>New event</h1>
        </div>
        <a href="/events/index.php" class="btn btn-ghost">Back to events</a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($duplicateWarning): ?>
        <div class="alert alert-warning">
            An event titled "<?= htmlspecialchars($duplicateWarning['title'], ENT_QUOTES, 'UTF-8') ?>"
            (code <?= htmlspecialchars((string) $duplicateWarning['event_code'], ENT_QUOTES, 'UTF-8') ?>,
            <?= htmlspecialchars($duplicateWarning['start_date'], ENT_QUOTES, 'UTF-8') ?> &ndash; <?= htmlspecialchars($duplicateWarning['end_date'], ENT_QUOTES, 'UTF-8') ?>)
            was created recently. If this is a different event, tick the box below and create it again.
            <label style="display:block;margin-top:.5rem;font-weight:normal;">
                <input type="checkbox" name="confirm_duplicate" value="1" form="event-form"> Create this as a separate event
            </label>
        </div>
    <?php endif; ?>

    <?php require __DIR__ . '/../templates/event_form.php'; ?>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="/assets/js/event-geo-form.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/event-geo-form.js') ?>"></script>
<script src="/assets/js/session-builder.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/session-builder.js') ?>"></script>
<script src="/assets/js/quiz-builder.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/quiz-builder.js') ?>"></script>
<script src="/assets/js/event-form-ui.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/event-form-ui.js') ?>"></script>
</body>
</html>
