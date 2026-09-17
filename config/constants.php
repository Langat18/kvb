<?php

declare(strict_types=1);

date_default_timezone_set('Africa/Nairobi');

const DEFAULT_GEOFENCE_RADIUS_M = 100;

if (!defined('OFFSITE_HARD_REJECT_EXCESS_M')) {
    define('OFFSITE_HARD_REJECT_EXCESS_M', 1000);
}
