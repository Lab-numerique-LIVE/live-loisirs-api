<?php
// -*- Constants -*-
// $DEBUG = getenv("DEBUG_API");
$DEBUG = true;

// URLs
const MAX_DESCRIPTION_SIZE = 180;
const NOW_HOURS_DELTA = 5;

// -*- libs loading -*-
// Logger
use \GuzzleHttp\Client;
use \GuzzleHttp\Promise;
use \Monolog\Formatter\LineFormatter;

// HTTP base requests/responses
use \Monolog\Handler\StreamHandler;
use \Monolog\Logger;
// HTTP Client for querying the remote services, through the Promise interface
use \Psr\Http\Message\ResponseInterface as Response;
use \Psr\Http\Message\ServerRequestInterface as Request;

// Loads compozer stuff
require '../vendor/autoload.php';

// Initialize the logger
$simpleLineFormat = "%channel%::%level_name% %message%\n";
$lineFormat = "%timestamp% %channel%::%level_name% %message%\n";
$logger = new Logger('loisirs-live-api');

if ($DEBUG) {
    $formatter = new LineFormatter($simpleLineFormat);
    $streamHandler = new StreamHandler('php://stdout', Logger::DEBUG);
    $streamHandler->setFormatter($formatter);
    $logger->pushHandler($streamHandler);
} else {
    $formatter = new LineFormatter($lineFormat);
    $streamHandler = new StreamHandler('/var/www/loisirs-live.tourcoing.fr/log/app.log', Logger::WARNING);
    $streamHandler->setFormatter($formatter);
    $logger->pushHandler($streamHandler);
}

// Sources
const API_SOURCES = [
    'TOURCOING' => [
        'source' => 'https://agenda.tourcoing.fr/flux/rss/',
        'type' => 'RSS',
        'enabled' => true,
        'function' => 'normalizeRSSEvents',
        'headers' => ['Accept' => 'application/rss+xml'],
    ],
    'MEL' => [
        'source' => 'https://openagenda.com/agendas/89904399/events.json?lang=fr&offset=0&limit=100',
        'type' => 'OpenAgenda',
        'enabled' => false,
        'function' => 'normalizeAgendaEvents',
        'headers' => ['Accept' => 'application/json'],
    ],
    'ROUBAIX' => [
        'source' => 'https://openagenda.com/agendas/9977986/events.json?lang=fr&offset=0&limit=100',
        'type' => 'OpenAgenda',
        'enabled' => true,
        'function' => 'normalizeOpenAgendaEvents',
        'headers' => ['Accept' => 'application/json'],
    ],
    'GRANDMIX' => [
        'source' => 'https://legrandmix.com/fr/events-feed?json',
        'type' => 'GrandMix',
        'enabled' => true,
        'function' => 'normalizeGrandmixEvents',
        'headers' => ['Accept' => 'application/json'],
    ],
];

// Locale / timezone definition
setlocale(LC_TIME, 'fr_FR');
\Moment\Moment::setLocale('fr_FR');

/**
 * Crops text at MAX_DESCRIPTION_SIZE and adds '...' at the end
 *
 * @param String $text Text to crop
 * @return String Croped string
 */
function cropText($text)
{
    global $logger;

    if (strlen($text) <= MAX_DESCRIPTION_SIZE) {
        return $text;
    }
    $cropedText = trim(substr($text, 0, MAX_DESCRIPTION_SIZE - 3)) . '...';
    return $cropedText;
}

/**
 * Strips out HTML and Special Characters
 *
 * @see https://stackoverflow.com/a/7128879/5727772
 *
 * @param String $text Text to strip
 * @return String Striped text
 */
function clearText($text)
{
    return preg_replace('/\s+/', ' ', urldecode(html_entity_decode(strip_tags(str_replace(['<br/>', '<BR/>'], ' ', $text)))));
}

/**
 * Gets the node element value
 *
 * @param DOMElement $element
 * @param String $name
 * @return String Extracted node value
 */
function getNodeValueForName($element, $name)
{
    $output = '';
    $extractedNode = $element->getElementsByTagName($name);
    if (count($extractedNode) > 0) {
        $output = $extractedNode[0]->nodeValue;
    }
    return $output;
}

/**
 * Decodes and Normalize the events from RSS source
 *
 * @param  String $eventsStream Events as an XML string
 * @return Array Array of formatted events
 */
function normalizeRSSEvents($eventsStream)
{
    global $logger;

    $events = [];

    $dom = new DOMDocument;
    $dom->loadXML($eventsStream);

    // builds array of events from DOM
    $eventNodes = $dom->getElementsByTagName('item');
    foreach ($eventNodes as $eventNode) {
        $locationNode = $eventNode->getElementsByTagName('location')[0];
        if (!$locationNode) {
            continue;
        }

        $publicNode = $eventNode->getElementsByTagName('public')[0];
        $rateNodes = $eventNode->getElementsByTagName('rate');

        // build rates array
        $ratesArray = [];
        foreach ($rateNodes as $rateNode) {
            $ratesArray[] = [
                'label' => getNodeValueForName($rateNode, 'label'),
                'type' => getNodeValueForName($rateNode, 'type'),
                'amount' => getNodeValueForName($rateNode, 'amount'),
                'condition' => getNodeValueForName($rateNode, 'condition'),
            ];
        }

        // prepare descriptions
        $longDescription = clearText(getNodeValueForName($eventNode, 'description'));
        $description = cropText($longDescription);

        $startDate = explode('T', $eventNode->getElementsByTagName('date_start')[0]->nodeValue)[0];
        $endDate = explode('T', $eventNode->getElementsByTagName('date_end')[0]->nodeValue)[0];

        // build event object
        $events[] = [
            'title' => getNodeValueForName($eventNode, 'title'),
            'description' => $description,
            'longDescription' => $longDescription,
            'url' => getNodeValueForName($eventNode, 'link'),
            'image' => $eventNode->getElementsByTagName('enclosure')[0]->getAttribute('url'),
            'category' => getNodeValueForName($eventNode, 'category'),
            'startDate' => $startDate,
            'endDate' => $endDate,
            'location' => [
                'latitude' => (float) $locationNode->getAttribute('latitude'),
                'longitude' => (float) $locationNode->getAttribute('longitude'),
                'name' => getNodeValueForName($locationNode, 'name'),
                'address' => clearText(getNodeValueForName($locationNode, 'address')),
                'email' => getNodeValueForName($locationNode, 'email'),
                'url' => getNodeValueForName($locationNode, 'link'),
                'area' => getNodeValueForName($locationNode, 'area'),
            ],
            'publics' => [
                'type' => getNodeValueForName($publicNode, 'type'),
                'label' => getNodeValueForName($publicNode, 'label'),
            ],
            'rates' => $ratesArray,
            'timings' => [],
        ];
    }
    return $events;
}

/**
 * Decodes and normalize events for OpenAgenda
 *
 * @param String $eventsStream
 * @return Array List on normalized events
 */
function normalizeOpenAgendaEvents($eventsStream)
{
    global $logger;
    $eventsArray = json_decode($eventsStream, true)['events'];

    $events = [];
    foreach ($eventsArray as $event) {
        $location = $event['location'];
        if (!$location) {
            continue;
        }

        $address = $location['address'] . ', ' . $location['postalCode'] . ' ' . $location['city'];
        $logger->addDebug("normalizeOpenAgendaEvents() timings = " . json_encode($event['timings'], JSON_PRETTY_PRINT));
        $events[] = [
            'title' => $event['title']['fr'],
            'description' => cropText(clearText($event['description']['fr'])),
            'longDescription' => clearText($event['longDescription']['fr']),
            'url' => $event['canonicalUrl'],
            'image' => $event['thumbnail'],
            'category' => '',
            'startDate' => $event['firstDate'],
            'endDate' => $event['lastDate'],
            'location' => [
                'latitude' => (float) $location['latitude'],
                'longitude' => (float) $location['longitude'],
                'name' => $location['name'],
                'address' => $address,
                'email' => '',
                'url' => $location['website'],
                'area' => '',
            ],
            'publics' => [
                'type' => '',
                'label' => '',
            ],
            'rates' => [],
            'timings' => $event['timings'],
        ];
    }

    return $events;
}

/**
 * Decodes and normalize events for GrandMix
 *
 * @param String $eventsStream
 * @return Array List on normalized events
 */
function normalizeGrandMixEvents($eventsStream)
{
    $eventsArray = json_decode($eventsStream, true)['events'];

    $events = [];
    foreach ($eventsArray as $event) {
        $location = $event['geolocations'];
        if (!$location) {
            continue;
        }

        $startDate = parseGrandMixDate($event['date_start']);
        $endDate = parseGrandMixDate($event['date_end']);

        $events[] = [
            'title' => $event['title'],
            'description' => cropText(clearText($event['body'])),
            'longDescription' => clearText($event['body']),
            'url' => $event['url'],
            'image' => str_replace('auto_1280', 'illustration_medium_crop', $event['picture']), // bug fix image url
            'category' => 'Concert',
            'startDate' => explode('T', $startDate)[0],
            'endDate' => explode('T', $endDate)[0],
            'location' => [
                'latitude' => (float) $location['lat'],
                'longitude' => (float) $location['long'],
                'name' => $location['label'] ? $location['label'] : '',
                'address' => clearText($location['address']),
                'email' => '',
                'url' => '',
                'area' => '',
            ],
            'publics' => [
                'type' => '',
                'label' => '',
            ],
            'rates' => [],
            'timings' => [
                [
                    'start' => $startDate,
                    'end' => $endDate,
                ],
            ],
        ];
    }
    return $events;
}

function parseGrandMixDate($date)
{
    global $logger;

    $logger->addDebug("parseGrandMixDate($date)");

    // fix day
    $date = str_replace(['lun. ', 'mar. ', 'mer. ', 'jeu. ', 'ven. ', 'sam. ', 'dim. '],
        ['', '', '', '', '', '', ''], $date);

    // fix month
    $date = str_replace(['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'],
        ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'], $date);

    $logger->addDebug("parseGrandMixDate($date)");
    $parsed_date = false;
    if (strlen($date) === 10 || strlen($date) === 11) {
        $parsed_date = DateTime::createFromFormat('d m H\Hi', $date);
    } else {
        $parsed_date = DateTime::createFromFormat('d m Y H\Hi', $date);
    }

    if (!is_bool($parsed_date)) {
        $_date = $parsed_date->format(DateTime::ISO8601);
        $logger->addDebug("parseGrandMixDate($date) new date = $_date");
        return $_date;
    } else {
        $errors = DateTime::getLastErrors();
        $logger->addWarning("parseGrandMixDate($date) Unable to parse date : " . json_encode($errors, JSON_PRETTY_PRINT));
        $_date = (new DateTime())->format(DateTime::ISO8601);
        $logger->addDebug("parseGrandMixDate($date) BUGGY new date = $_date");
        return $_date;
    }
}

/**
 * @see https://stackoverflow.com/a/2910637/5727772
 *
 * @param [type] $a
 * @param [type] $b
 * @return void
 */
function compareDates($a, $b, $sortOn = 'startDate', $order = 'ASC')
{
    $t1 = strtotime($a[$sortOn]);
    $t2 = strtotime($b[$sortOn]);
    return $order === 'ASC' ? $t1 - $t2 : $t2 - $t1;
}

/**
 *
 *
 * @param array $events
 * @return array
 */
function sortEvents($events)
{
    // order by ASC on startDate
    usort($events, function ($a, $b) {
        return compareDates($a, $b, 'startDate', 'ASC');
    });

    return $events;
}

/**
 * Get all events from various sources, according to `API_SOURCES`
 *
 * @return Array ordered events from enabled sources
 */
function getAllEvents()
{
    global $logger;
    $client = new Client();

    $results = [];

    foreach (API_SOURCES as $key => $value) {
        if ($value['enabled'] === false) {
            $logger->addInfo("getAllEvents() Does not get data for source $key: disabled.");
            continue;
        }
        // Adds the results
        $results += [$key => $client
                ->getAsync($value['source'], $value['headers'])
                ->then(
                    function ($response) use ($key, $value) {
                        global $logger;
                        $content = $response->getBody()->getContents();
                        $logger->addDebug("getAllEvents::then($key) strlen(\$content) = " . strlen($content));
                        $_results = $value['function']($content);
                        $logger->addDebug("getAllEvents::then($key) count(\$local_results) = " . count($_results));
                        return $_results;
                    },
                    function ($reason) use ($key, $value) {
                        global $logger;
                        // global $key, $value;
                        $logger->addError('getAllEvents::then(' . $key . ') Error while getting data: ' . $reason);
                        return [];
                    })];
    }
    $all_results = Promise\unwrap($results);

    // Merges the results in a single array
    $merged = [];
    foreach ($all_results as $key => $value) {
        $merged = array_merge($merged, $value);
    }

    // Sorts results
    return sortEvents($merged);
}

/**
 * Filters the next events according to $days parameter
 *
 * @param Array $events List of events
 * @param Integer $days Count of days
 * @return Array New events filtered
 */
function filterNextEvents($events, $days = 7)
{
    $day = new \Moment\Moment();
    $nextEvents = [];

    for ($i = 0; $i < $days; $i += 1) {
        $dayNumber = (int) $day->getWeekday();
        $dayNumber = $dayNumber === 7 ? 0 : $dayNumber; // fix sunday number for js usage

        $nextEvents[] = [
            'date' => $day->format(),
            'dayNumber' => $dayNumber,
            'dayName' => ucfirst($day->getWeekdayNameShort()),
            'events' => array_values(array_filter($events, function ($event) use ($day) {
                // $day - $startEvent <= 0 AND $day - $endEvent >= 0
                // meaning the event is started or starts today and isn't ended or ends today
                return intval($day->from($event['startDate'])->getDays()) <= 0 && intval($day->from($event['endDate'])->getDays()) >= 0;
            })),
        ];

        // next day
        $day->addDays(1);
    }

    return $nextEvents;
}

/**
 * Filters events in the next hour
 *
 * @param array $events
 * @return void
 */
function filterInHourEvents($events)
{
    $day = new \Moment\Moment();

    return array_values(array_filter($events, function ($event) use ($day) {
        if (count($event['timings']) === 0) {
            return false;
        }

        // we check is there is timing is the future today
        $timings = array_filter($event['timings'], function ($timing) use ($day) {
            $hoursDelta = $day->from($timing['start'])->getHours();

            return $hoursDelta >= 0 && $hoursDelta <= NOW_HOURS_DELTA;
        });

        return count($timings) > 0;
    }));
}

/**
 * Routing
 */
$container = new \Slim\Container([
    'settings' => [
        'displayErrorDetails' => true,
    ],
]);
$app = new \Slim\App($container);

// CORS
$app->add(new \CorsSlim\CorsSlim());

/**
 * Returns all next events
 */
$app->get('/events', function (Request $req, Response $res) {
    $events = getAllEvents();

    return $res->withJson($events, null, JSON_PARTIAL_OUTPUT_ON_ERROR);
});

/**
 * Returns next 7 days events
 */
$app->get('/events/7days', function (Request $req, Response $res) {
    $events = getAllEvents();
    $events = filterNextEvents($events);
    return $res->withJson($events, null, JSON_PARTIAL_OUTPUT_ON_ERROR);
});

/**
 * Returns events in the next hour
 */
$app->get('/events/now', function (Request $req, Response $res) {
    $events = getAllEvents();
    $events = filterInHourEvents($events);
    return $res->withJson($events, null, JSON_PARTIAL_OUTPUT_ON_ERROR);
});

$app->run();
