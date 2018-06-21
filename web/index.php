<?php
// -*- Constants -*-
$DEBUG = getenv("DEBUG_API");

const API_SOURCES = [
    'TOURCOING' => [
        'source' => 'https://agenda.tourcoing.fr/flux/rss/', 
        'type' => 'RSS', 
        'enabled' => true
    ], 
    'MEL' => [
        'source' => 'https://openagenda.com/agendas/89904399/events.json?lang=fr', 
        'type' => 'OpenAgenda', 
        'enabled' => false
    ], 
    'ROUBAIX' => [
        'source' => 'https://openagenda.com/agendas/9977986/events.json?lang=fr', 
        'type' => 'OpenAgenda', 
        'enabled' => true
    ],
    'GRANDMIX' => [
        'source' => 'https://legrandmix.com/fr/events-feed?json', 
        'type' => 'GrandMix', 
        'enabled' => true
    ],
];

// URLs
const MAX_DESCRIPTION_SIZE = 180;
const NOW_HOURS_DELTA = 5;

// -*- libs loading -*-
// Logger
use \Monolog\Logger;
use \Monolog\Handler\StreamHandler;
use \Monolog\Formatter\LineFormatter;

// HTTP base requests/responses
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
// HTTP Client for querying the remote services, through the Promise interface
use \GuzzleHttp\Promise;
use \GuzzleHttp\Client;

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
    $streamHandler = new StreamHandler('/var/www/loisirs-live.tourcoing.fr/log/app.log', Logger::DEBUG);
    $streamHandler->setFormatter($formatter);
    $logger->pushHandler($streamHandler);
}

// Locale definition
setlocale(LC_TIME, 'fr_FR');
\Moment\Moment::setLocale('fr_FR');

/**
 * Crops text at MAX_DESCRIPTION_SIZE and adds '...' at the end
 *
 * @param [string] $text
 * @return [string]
 */
function cropText($text) {
    global $logger;

    if (strlen($text) <= MAX_DESCRIPTION_SIZE) {
        return $text;
    }
    $cropedText =  trim(substr($text, 0, MAX_DESCRIPTION_SIZE - 3)) . '...';
    return $cropedText;
}

/**
 * Strips out HTML and Special Characters
 * @see https://stackoverflow.com/a/7128879/5727772
 *
 * @param String $text
 * @return String
 */
function clearText($text) {
    return preg_replace('/\s+/', ' ', urldecode(html_entity_decode(strip_tags(str_replace(['<br/>', '<BR/>'], ' ', $text)))));
}

/**
 * Gets the node element value
 * 
 * @param DOMElement $element
 * @param String $name
 * @return String Extracted node value
 */
function getNodeValueForName($element, $name) {
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
 * @param  String $xmlEvents Events as an XML string
 * @return Array Array of formatted events
 */
function normalizeRSSEvents($xmlEvents) {
    global $logger;
    
    $events = [];
    
    $dom = new DOMDocument;
    $dom->loadXML($xmlEvents);

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
                'type' =>  getNodeValueForName($publicNode, 'type'),
                'label' => getNodeValueForName($publicNode, 'label')
            ],
            'rates' => $ratesArray,
            'timings' => []
        ];
    }

    $logger->addDebug('normalizeRSSEvents() $events = ' . json_encode($events, JSON_PRETTY_PRINT));

    return $events;
}

/**
 * Decodes and normalize events for OpenAgenda
 *
 * @param String $jsonEvents
 * @return Array List on normalized events
 */
function normalizeOpenAgendaEvents($jsonEvents) {
    $eventsArray = json_decode($jsonEvents, true)['events'];

    $events = [];
    foreach ($eventsArray as $event) {
        $location = $event['location'];
        if (!$location) {
            continue;
        }

        $address = $location['address'] . ', ' . $location['postalCode'] . ' ' . $location['city'];

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
                'area' => ''
            ],
            'publics' => [
                'type' => '',
                'label' => ''
            ],
            'rates' => [],
            'timings' => $event['timings']
        ];
    }

    return $events;
}

/**
 * Decodes and normalize events for GrandMix
 *
 * @param String $jsonEvents
 * @return Array List on normalized events
 */
function normalizeGrandMixEvents($jsonEvents) {
    $eventsArray = json_decode($jsonEvents, true)['events'];

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
                'area' => ''
            ],
            'publics' => [
                'type' => '',
                'label' => ''
            ],
            'rates' => [],
            'timings' => [
                [
                    'start' => $startDate,
                    'end' => $endDate
                ]
            ]
        ];
    }
    return $events;
}

function parseGrandMixDate($date) {
    // fix day
    $date = str_replace(['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'], 
                        ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], $date);

    // fix month
    $date = str_replace(['janv', 'fév', 'mars', 'avr', 'mai', 'juin', 'juil', 'août', 'sept', 'oct', 'nov', 'déc'], 
                        ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], $date);
    $clean_date = DateTime::createFromFormat('D d M H\Hi', $date);
    if (! is_bool($clean_date)) {
        return $clean_date->format(DateTime::ISO8601);
    } else {
        return (new DateTime())->format(DateTime::ISO8601);
    }
}

/**
 * @see https://stackoverflow.com/a/2910637/5727772
 *
 * @param [type] $a
 * @param [type] $b
 * @return void
 */
function compareDates($a, $b, $sortOn = 'startDate', $order = 'ASC') {
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
function sortEvents($events) {
    // order by ASC on startDate
    usort($events, function ($a, $b) {
        return compareDates($a, $b, 'startDate', 'ASC');
    });

    return $events;
}

/**
 * Get Tourcoing events from RSS
 *
 * @param [type] $client
 * @return Promise
 */
function getTourcoingEvents($client) {
    global $logger;
    $source= API_SOURCES['TOURCOING']['source'];
    $logger->addDebug('getTourcoingEvents() $source = ' . $source);

    return $client
        -> getAsync($source, [
            HEADERS => [ 
                ACCEPT => 'application/rss+xml'
                ]
        ])
        -> then(function ($response) {
            $xmlEvents = $response->getBody()->getContents();
            return normalizeRSSEvents($xmlEvents);
        }, function ($reason) {
            //TODO logging
            log_error($reason);
            return [];
        });
}

/**
 * Get Roubaix events from JSON
 *
 * @param [type] $client
 * @return Promise
 */
function getRoubaixEvents($client)
{
    global $logger;

    $source= API_SOURCES['ROUBAIX']['source'];
    $logger->addDebug('getRoubaixEvents() $source = ' . $source);

    return $client
        ->getAsync($source . '?offset=0&limit=100', [
            HEADERS => [
                ACCEPT => 'application/json'
            ]
        ])
        ->then(function ($response) {
            $jsonEvents = $response->getBody()->getContents();

            return normalizeOpenAgendaEvents($jsonEvents);
        }, function ($reason) {
            log_error($reason);
            return [];
        });
}

/**
 * Get Le grand mix events from JSON
 *
 * @param [type] $client
 * @return Promise
 */
function getGrandmixEvents($client)
{
    global $logger;
    $source = API_SOURCES['GRANDMIX']['source'];
    $logger->addDebug('getGrandmixEvents() $source = ' . $source);

    return $client
        ->getAsync($source, [
            HEADERS => [
                ACCEPT => 'application/json'
            ]
        ])
        ->then(function ($response) {
            $jsonEvents = $response->getBody()->getContents();
            return normalizeGrandMixEvents($jsonEvents);
        }, function ($reason) {
            //TODO logging
            
            return [];
        });
}

const TOURCOING_EVENTS = 'tourcoingsEvents';
const ROUBAIX_EVENTS = 'roubaixEvents';
const GRANDMIX_EVENTS = 'grandmixEvents';


/**
 * Get all events from various sources
 *
 * @return array
 */
function getAllEvents() {
    global $logger;
    $client = new Client();

    $results = Promise\unwrap([
        TOURCOING_EVENTS => getTourcoingEvents($client),
        ROUBAIX_EVENTS => getRoubaixEvents($client),
        GRANDMIX_EVENTS => getGrandmixEvents($client)
    ]);
    
    $logger->addDebug("getAllEvents() tourcoingsEvents = " . json_encode($results[TOURCOING_EVENTS], JSON_PRETTY_PRINT));
    $logger->addDebug("getAllEvents() roubaixEvents = " . json_encode($results[ROUBAIX_EVENTS], JSON_PRETTY_PRINT));
    $logger->addDebug("getAllEvents() grandmixEvents = " . json_encode($results[GRANDMIX_EVENTS], JSON_PRETTY_PRINT));

    return sortEvents(array_merge(
        $results[TOURCOING_EVENTS],
        $results[ROUBAIX_EVENTS],
        $results[GRANDMIX_EVENTS]
    ));
}

/**
 * Filters the next events according to $days parameter
 *
 * @param Array $events List of events
 * @param Integer $days Count of days
 * @return Array New events filtered
 */
function filterNextEvents($events, $days = 7) {
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
            }))
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
function filterInHourEvents($events) {
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