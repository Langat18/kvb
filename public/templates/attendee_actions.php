<?php
/** @var array $event */
/** @var array $p */
$query = 'token=' . urlencode((string) $event['view_token']) . '&amp;reg_no=' . urlencode((string) $p['reg_no']);
$name  = htmlspecialchars((string) $p['name'], ENT_QUOTES, 'UTF-8');
?>
<div class="act-menu">
    <button type="button" class="act-btn" data-menu aria-haspopup="menu" aria-expanded="false" aria-label="Actions for <?= $name ?>">Actions</button>
    <div class="act-list" role="menu" hidden>
        <?php if (\VetBoard\Auth::can('edit_attendee_details')): ?>
            <a role="menuitem" href="/events/edit_attendee.php?<?= $query ?>">Edit details</a>
        <?php endif; ?>
        <?php if (\VetBoard\Auth::can('award_manual_points')): ?>
            <a role="menuitem" href="/events/award_points.php?<?= $query ?>">Add points</a>
        <?php endif; ?>
        <?php if (\VetBoard\Auth::can('delete_registrations')): ?>
            <a role="menuitem" class="danger" href="/events/delete_registration.php?<?= $query ?>">Delete registration</a>
        <?php endif; ?>
    </div>
</div>
