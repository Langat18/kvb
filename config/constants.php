<?php

declare(strict_types=1);

define('MORNING_SESSION_START', '08:00');
define('MORNING_SESSION_END', '10:30');
define('MID_MORNING_SESSION_START', '11:00');
define('MID_MORNING_SESSION_END', '13:00');
define('AFTERNOON_SESSION_START', '14:00');
define('AFTERNOON_SESSION_END', '17:00');

const EVENT_MODES = [
    'onsite'  => 'On-site',
    'virtual' => 'Virtual',
    'hybrid'  => 'Hybrid',
];

const CADRE_LABELS = [
    'veterinary_surgeon' => 'Veterinary Surgeon',
    'vet_bachelors'       => 'Veterinary Doctor (Bachelors)',
    'vet_diploma'         => 'Veterinary Doctor (Diploma)',
    'vet_certificate'     => 'Veterinary Doctor (Certificate)',
];

const SESSION_LABELS = [
    'morning'     => 'Morning',
    'mid_morning' => 'Mid-Morning',
    'afternoon'   => 'Afternoon',
];

const DEFAULT_GEOFENCE_RADIUS_M = 100;
