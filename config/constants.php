<?php

declare(strict_types=1);

date_default_timezone_set('Africa/Nairobi');

const DEFAULT_GEOFENCE_RADIUS_M = 100;

if (!defined('OFFSITE_HARD_REJECT_EXCESS_M')) {
    define('OFFSITE_HARD_REJECT_EXCESS_M', 1000);
}

 
if (!defined('VIRTUAL_SIGNIN_LEAD_MINUTES')) {
    define('VIRTUAL_SIGNIN_LEAD_MINUTES', 15);
}

if (!defined('ONSITE_SIGNIN_LEAD_MINUTES')) {
    define('ONSITE_SIGNIN_LEAD_MINUTES', 15);
}

// Sign-in stays open this many minutes after a session ends (0 = off).
if (!defined('LATE_SIGNIN_GRACE_MINUTES')) {
    define('LATE_SIGNIN_GRACE_MINUTES', 60);
}

if (!defined('MEETING_PROVIDERS')) {
    define('MEETING_PROVIDERS', [
        'google_meet' => 'Google Meet',
        'teams'       => 'Microsoft Teams',
        'zoom'        => 'Zoom',
        'jitsi'       => 'Jitsi',
        'kenet'       => 'KENET',
        'other'       => 'Other',
    ]);
}
