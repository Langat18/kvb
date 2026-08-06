<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

use Eluceo\iCal\Domain\Entity\Calendar;
use Eluceo\iCal\Domain\Entity\Event as IcsEvent;
use Eluceo\iCal\Domain\ValueObject\Date;
use Eluceo\iCal\Domain\ValueObject\Location;
use Eluceo\iCal\Domain\ValueObject\MultiDay;
use Eluceo\iCal\Domain\ValueObject\TimeSpan;
use Eluceo\iCal\Domain\ValueObject\UniqueIdentifier;
use Eluceo\iCal\Presentation\Factory\CalendarFactory;
use VetBoard\Event;

if (empty($_GET['token'])) {
    http_response_code(400);
    die('Invalid event link.');
}

$eventModel = new Event();
$event      = $eventModel->findByToken(trim($_GET['token']));

if (!$event) {
    http_response_code(404);
    die('Event not found.');
}

$uid = $event['calendar_uid'] ?: ('vetboard-event-' . $event['id']);

$startDate = new \DateTimeImmutable($event['start_date']);
$endDate   = (new \DateTimeImmutable($event['end_date']))->modify('+1 day');

$icsEvent = new IcsEvent(new UniqueIdentifier($uid));
$icsEvent
    ->setSummary($event['title'])
    ->setOccurrence(new MultiDay(new Date($startDate), new Date($endDate)));

if (!empty($event['venue'])) {
    $icsEvent->setLocation(new Location($event['venue']));
}

$description = 'Mode: ' . (EVENT_MODES[$event['event_mode']] ?? $event['event_mode']);
if (!empty($event['coordinator'])) {
    $description .= "\nCoordinator: " . $event['coordinator'];
}
$icsEvent->setDescription($description);

$calendar = new Calendar([$icsEvent]);

$componentFactory = new CalendarFactory();
$calendarComponent = $componentFactory->createCalendar($calendar);

$filename = 'Event_' . preg_replace('/[^a-zA-Z0-9]/', '_', $event['title']) . '.ics';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo $calendarComponent;
exit;
