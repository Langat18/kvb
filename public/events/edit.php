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
Auth::requirePermission('view_events');

$user = Auth::user();

if (empty($_GET['token'])) {
    header('Location: /events/index.php');
    exit;
}

$eventModel = new Event();
$event      = $eventModel->findByViewToken(trim($_GET['token']));

if (!$event) {
    $_SESSION['error'] = 'Event not found.';
    header('Location: /events/index.php');
    exit;
}

$canEdit = Auth::can('manage_events') || (int) $user['id'] === (int) ($event['created_by'] ?? 0);

if (!$canEdit) {
    http_response_code(403);
    die('Access denied.');
}

$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/index.php'],
    ['label' => 'Events', 'url' => '/events/index.php'],
    ['label' => $event['title'], 'url' => '/events/view.php?token=' . urlencode($event['view_token'])],
    ['label' => 'Edit', 'url' => null],
];

$eventModeRepo = new EventModeRepository();
$eventModes    = $eventModeRepo->list(true);
$quizModel     = new EventQuiz();

$error    = $_SESSION['error']     ?? null;
$success  = $_SESSION['success']   ?? null;
$formData = $_SESSION['form_data'] ?? [];
unset($_SESSION['error'], $_SESSION['success'], $_SESSION['form_data']);


function parseQuizSubmission(array $post, int $eventId): array
{
    $result = [];

    foreach ($post as $swid => $swdata) {
        $swid = (string) $swid;

        $expectedCount = (int) ($swdata['expected_count'] ?? 0);
        $actualCount   = count($swdata['questions'] ?? []);

        if ($expectedCount !== $actualCount) {
            error_log(
                "Quiz integrity mismatch — event {$eventId}, session_window {$swid}: " .
                "browser reported {$expectedCount} question block(s) at submit time, " .
                "server received {$actualCount} distinct question(s) in \$_POST. " .
                "This usually means two question blocks shared an array index client-side."
            );
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

                $options[] = [
                    'text'    => $optText,
                    'correct' => ((string) $idx === $correctIdx),
                ];
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

/** Normalizes a PDO boolean value. */
function isTruthyDbBool($value): bool
{
    return $value === true || $value === 't' || $value === 1 || $value === '1';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    $data = [
        'title'                     => trim($_POST['title']             ?? ''),
        'event_code'                => trim($_POST['event_code']        ?? ''),
        'event_mode_id'             => $_POST['event_mode_id']  !== '' ? (int) $_POST['event_mode_id'] : null,
        'venue'                     => trim($_POST['venue']              ?? ''),
        'provider_id'               => $_POST['provider_id']    !== '' ? (int) $_POST['provider_id'] : null,
        'provider_accreditation_no' => $_POST['provider_accreditation_no'] ?? '',
        'coordinator'               => trim($_POST['coordinator']        ?? ''),
        'start_date'                => $_POST['start_date']              ?? '',
        'end_date'                  => $_POST['end_date']                ?? '',
        'geo_lat'                   => $_POST['geo_lat']    !== '' ? (float) $_POST['geo_lat']    : null,
        'geo_lng'                   => $_POST['geo_lng']    !== '' ? (float) $_POST['geo_lng']    : null,
        'geo_radius_m'              => $_POST['geo_radius_m'] !== '' ? (int) $_POST['geo_radius_m'] : null,
        'meeting_provider'          => $_POST['meeting_provider'] ?? '',
        'meeting_link'              => $_POST['meeting_link']      ?? '',
        'day_confirmation'          => ($_POST['day_confirmation'] ?? '') === '1',
        'sessions'                  => $_POST['sessions'] ?? [],
        'presentations'             => $_POST['presentations'] ?? [],
    ];

    try {
        $updateResult = $eventModel->update((int) $event['id'], $data);
        $sessionIdMap = $updateResult['session_id_map'];
    } catch (\InvalidArgumentException $e) {
        $_SESSION['error']     = $e->getMessage();
        $_SESSION['form_data'] = $data;
        header('Location: /events/edit.php?token=' . urlencode($event['view_token']));
        exit;
    } catch (\Throwable $e) {
        error_log('Event update error: ' . $e->getMessage());
        $_SESSION['error']     = 'A system error occurred. Please try again.';
        $_SESSION['form_data'] = $data;
        header('Location: /events/edit.php?token=' . urlencode($event['view_token']));
        exit;
    }

    $updatedMode = $eventModeRepo->findById((int) $data['event_mode_id']);
    $isOnsiteNow = ($updatedMode['item_key'] ?? '') === 'onsite';

    try {
        if ($isOnsiteNow) {
            foreach ($eventModel->getSessions((int) $event['id']) as $sw) {
                $quizModel->saveQuestions((int) $event['id'], (int) $sw['id'], []);
            }
        } else {
            foreach (parseQuizSubmission($_POST['quiz'] ?? [], (int) $event['id']) as $swid => $q) {
                $realSwid = $sessionIdMap[$swid] ?? null;
                if ($realSwid === null) {
                    continue;
                }
                $quizModel->saveQuestions((int) $event['id'], $realSwid, $q['questions']);
            }
        }

        $_SESSION['success'] = 'Event updated successfully.';
        header('Location: /events/view.php?token=' . urlencode($event['view_token']));
        exit;

    } catch (\InvalidArgumentException $e) {
        $_SESSION['error'] = 'Event details were saved, but a quiz could not be saved (' . $e->getMessage() . '). Please fix it below and save again.';
        header('Location: /events/edit.php?token=' . urlencode($event['view_token']));
        exit;

    } catch (\Throwable $e) {
        error_log('Quiz save error: ' . $e->getMessage());
        $_SESSION['error'] = 'Event details were saved, but the quiz could not be saved due to a system error. Please try again below.';
        header('Location: /events/edit.php?token=' . urlencode($event['view_token']));
        exit;
    }
}

$v = empty($formData) ? $event : array_merge($event, $formData);

if (isset($formData['sessions'])) {
    $sessionsForDisplay = [];
    foreach ($formData['sessions'] as $clientId => $s) {
        $sessionsForDisplay[] = [
            'id'         => $clientId,
            'label'      => $s['label'] ?? '',
            'start_time' => $s['start_time'] ?? '',
            'end_time'   => $s['end_time'] ?? '',
            'points'     => (string) ($s['points'] ?? 0),
            'session_date' => $s['session_date'] ?? '',
            'require_late_signin' => !empty($s['require_late_signin']),
            'signin_lead_minutes'  => $s['signin_lead_minutes'] ?? '',
            'signin_grace_minutes' => $s['signin_grace_minutes'] ?? '',
        ];
    }
} else {
    $sessionsForDisplay = array_map(function ($row) {
        return [
            'id'         => $row['id'],
            'label'      => $row['label'],
            'start_time' => substr($row['start_time'], 0, 5),
            'end_time'   => substr($row['end_time'], 0, 5),
            'points'     => \VetBoard\Points::format($row['points']),
            'session_date' => $row['session_date'] ?? '',
            'require_late_signin' => array_key_exists('require_late_signin', $row) ? (bool) $row['require_late_signin'] : true,
            'signin_lead_minutes'  => $row['signin_lead_minutes'] ?? '',
            'signin_grace_minutes' => $row['signin_grace_minutes'] ?? '',
        ];
    }, $eventModel->getSessions((int) $event['id']));
}

$providerNameDisplay = empty($formData) ? ($event['provider_name'] ?? '') : '';

$presentationsBySession = empty($formData)
    ? (new \VetBoard\SessionPresentation())->forEvent((int) $event['id'])
    : (array) ($formData['presentations'] ?? []);

$formAction  = '/events/edit.php?token=' . urlencode($event['view_token']);
$submitLabel = 'Save changes';
$cancelUrl   = '/events/view.php?token=' . urlencode($event['view_token']);

require __DIR__ . '/../templates/sidebar_data.php';

$quizBySession = [];
foreach ($sessionsForDisplay as $sw) {
    $quizBySession[$sw['id']] = is_numeric($sw['id'])
        ? $quizModel->getQuestions((int) $event['id'], (int) $sw['id'], true)
        : [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Event - Kenya Veterinary Board</title>
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
            <h1>Edit event</h1>
        </div>
        <a href="<?= htmlspecialchars($cancelUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-ghost">Back to event</a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
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
