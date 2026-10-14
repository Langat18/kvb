<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use VetBoard\Auth;
use VetBoard\SigninRules;

Auth::startSession();
Auth::requirePermission('manage_lookups');
$user = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid security token.');
    }

    try {
        SigninRules::save((array) ($_POST['rules'] ?? []), (int) ($user['id'] ?? 0));
        $_SESSION['success'] = 'Sign-in rules updated.';
    } catch (\InvalidArgumentException $e) {
        $_SESSION['error'] = $e->getMessage();
    } catch (\Throwable $e) {
        error_log('Sign-in rules update error: ' . $e->getMessage());
        $_SESSION['error'] = 'A system error occurred. Please try again.';
    }

    header('Location: /settings/signin.php');
    exit;
}

$success = $_SESSION['success'] ?? null;
$error   = $_SESSION['error']   ?? null;
unset($_SESSION['success'], $_SESSION['error']);

$breadcrumbs = [['label' => 'Dashboard', 'url' => '/index.php'], ['label' => 'Sign-in Rules', 'url' => null]];
$updatedAt   = SigninRules::lastUpdated();

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

require __DIR__ . '/../templates/sidebar_data.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign-in Rules - Kenya Veterinary Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="has-app-sidebar">
<?php require __DIR__ . '/../templates/nav.php'; ?>
<div class="page-with-sidebar">
    <div class="dash-main-card">
        <div class="section-label">Sign-in Rules</div>
        <p>Controls when attendees can sign in to earn points. Changes apply to all events immediately.</p>

        <?php if ($success): ?><div class="flash flash-success"><?= $h($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="flash flash-error"><?= $h($error) ?></div><?php endif; ?>

        <form method="POST" action="/settings/signin.php">
            <input type="hidden" name="csrf_token" value="<?= $h(Auth::csrfToken()) ?>">

            <div class="form-grid">
                <?php foreach (SigninRules::RULES as $key => $rule): ?>
                <div class="form-group">
                    <label for="rule_<?= $h($key) ?>"><?= $h($rule['label']) ?></label>
                    <input type="number" id="rule_<?= $h($key) ?>" name="rules[<?= $h($key) ?>]"
                           min="<?= $rule['min'] ?>" max="<?= $rule['max'] ?>" step="1" required
                           value="<?= SigninRules::get($key) ?>">
                    <span class="field-hint">
                        <?= $h($rule['hint']) ?> Allowed: <?= $rule['min'] ?>&ndash;<?= $rule['max'] ?> minutes.
                    </span>
                </div>
                <?php endforeach; ?>
            </div>

            <button type="submit" class="btn btn-primary">Save changes</button>
        </form>

        <div class="section-label" style="margin-top: var(--space-4);">Attendee message</div>
        <p><strong>Virtual:</strong> <?= $h(SigninRules::describe('virtual')) ?></p>
        <p><strong>On-site:</strong> <?= $h(SigninRules::describe('onsite')) ?></p>

        <?php if ($updatedAt): ?>
            <p class="field-hint">Last updated <?= $h(date('d M Y, H:i', strtotime($updatedAt))) ?></p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
