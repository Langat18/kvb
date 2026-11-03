<?php
/** @var array $event */
/** @var array $p */
$query = 'token=' . urlencode((string) $event['view_token']) . '&amp;reg_no=' . urlencode((string) $p['reg_no']);
?>
<div class="row-actions">
    <?php if (\VetBoard\Auth::can('edit_attendee_details')): ?>
        <a href="/events/edit_attendee.php?<?= $query ?>" class="btn btn-ghost btn-sm">Edit</a>
    <?php endif; ?>
    <?php if (\VetBoard\Auth::can('award_manual_points')): ?>
        <a href="/events/award_points.php?<?= $query ?>" class="btn btn-ghost btn-sm">Add points</a>
    <?php endif; ?>
    <?php if (\VetBoard\Auth::can('delete_registrations')): ?>
        <a href="/events/delete_registration.php?<?= $query ?>" class="btn btn-danger btn-sm">Delete</a>
    <?php endif; ?>
</div>
