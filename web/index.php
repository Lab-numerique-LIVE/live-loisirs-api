<?php

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

use \GuzzleHttp\Promise;
use \GuzzleHttp\Client;

require '../vendor/autoload.php';

const API_BASE_TOURCOING_RSS = 'https://agenda.tourcoing.fr/flux/rss/';
const API_BASE_ROUBAIX_JSON = 'https://openagenda.com/agendas/9977986/events.json?lang=fr';
const API_BASE_GRANDMIX_JSON = 'https://legrandmix.com/fr/events-feed?json';

const MAX_DESCRIPTION_SIZE = 180;

const NOW_HOURS_DELTA = 5;

setlocale(LC_TIME, "fr_FR");
\Moment\Moment::setLocale('fr_FR');

/**
 * Crops text at MAX_DESCRIPTION_SIZE and adds '...' at the end
 *
 * @param [string] $text
 * @return [string]
 */
function cropText($text) {
    if (strlen($text) <= MAX_DESCRIPTION_SIZE) {
        return $text;
    }

    return trim(substr($text, 0, MAX_DESCRIPTION_SIZE - 3)) . '...';
}

/**
 * @see https://stackoverflow.com/a/7128879/5727772
 *
 * @param [string] $text
 * @return [string]
 */
function clearText($text) {
    return preg_replace('/\s+/', ' ', urldecode(html_entity_decode(strip_tags(str_replace(['<br/>', '<BR/>'], ' ', $text)))));
}

/**
 *
 * @param  String $xmlEvents
 * @return Array
 */
function tourcoingEventsNormalizer($xmlEvents)
{
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
                'label' => $rateNode->getElementsByTagName('label')[0]->nodeValue,
                'type' => $rateNode->getElementsByTagName('type')[0]->nodeValue,
                'amount' => $rateNode->getElementsByTagName('amount')[0]->nodeValue,
                'condition' => $rateNode->getElementsByTagName('condition')[0]->nodeValue
            ];
        }

        // prepare descriptions
        $longDescription = clearText($eventNode->getElementsByTagName('description')[0]->nodeValue);
        $description = cropText($longDescription);

        $startDate = explode('T', $eventNode->getElementsByTagName('date_start')[0]->nodeValue)[0];
        $endDate = explode('T', $eventNode->getElementsByTagName('date_end')[0]->nodeValue)[0];

        // build event object
        $events[] = [
            'title' => $eventNode->getElementsByTagName('title')[0]->nodeValue,
            'description' => $description,
            'longDescription' => $longDescription,
            'url' => $eventNode->getElementsByTagName('link')[0]->nodeValue,
            'image' => $eventNode->getElementsByTagName('enclosure')[0]->getAttribute('url'),
            'category' => $eventNode->getElementsByTagName('category')[0]->nodeValue,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'location' => [
                'latitude' => (float) $locationNode->getAttribute('latitude'),
                'longitude' => (float) $locationNode->getAttribute('longitude'),
                'name' => $locationNode->getElementsByTagName('name')[0]->nodeValue,
                'address' => clearText($locationNode->getElementsByTagName('address')[0]->nodeValue),
                'email' => $locationNode->getElementsByTagName('email')[0]->nodeValue,
                'url' => $locationNode->getElementsByTagName('link')[0]->nodeValue,
                'area' => $locationNode->getElementsByTagName('area')[0]->nodeValue
            ],
            'publics' => [
                'type' => $publicNode->getElementsByTagName('type')[0]->nodeValue,
                'label' => $publicNode->getElementsByTagName('label')[0]->nodeValue
            ],
            'rates' => $ratesArray,
            'timings' => []
        ];
    }

    return $events;
}

/**
 * Undocumented function
 *
 * @param [type] $jsonEvents
 * @return void
 */
function roubaixEventsNormalizer($jsonEvents)
{
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
 * Undocumented function
 *
 * @param [type] $jsonEvents
 * @return void
 */
function grandmixEventsNormalizer($jsonEvents)
{
    $eventsArray = json_decode($jsonEvents, true)['events'];

    $events = [];
    foreach ($eventsArray as $event) {
        $location = $event['geolocations'];
        if (!$location) {
            continue;
        }

        $startDate = parseGrandmixDate($event['date_start']);
        $endDate = parseGrandmixDate($event['date_end']);

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
                'name' => $location['label'] ? $location['label'] : "",
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

function parseGrandmixDate($date) {
    // fix day
    $date = str_replace(['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'], ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], $date);

    // fix month
    $date = str_replace(['janv', 'fév', 'mars', 'avr', 'mai', 'juin', 'juil', 'août', 'sept', 'oct', 'nov', 'déc'], ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], $date);

    return DateTime::createFromFormat('D d M H\Hi', $date)->format(DateTime::ISO8601);
}

/**
 * @see https://stackoverflow.com/a/2910637/5727772
 *
 * @param [type] $a
 * @param [type] $b
 * @return void
 */
function date_compare($a, $b, $sortOn = 'startDate', $order = 'ASC')
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
function sortEvents($events) {
    // order by ASC on startDate
    usort($events, function ($a, $b) {
        return date_compare($a, $b, 'startDate', 'ASC');
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
    return $client
        ->getAsync(API_BASE_TOURCOING_RSS, [
            'headers' => [
                'Accept' => 'application/rss+xml'
            ]
        ])
        ->then(function ($response) {
            $xmlEvents = $response->getBody()->getContents();

            return tourcoingEventsNormalizer($xmlEvents);
        }, function ($reason) {
            //TODO logging
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
    return $client
        ->getAsync(API_BASE_ROUBAIX_JSON . '?offset=0&limit=100', [
            'headers' => [
                'Accept' => 'application/json'
            ]
        ])
        ->then(function ($response) {
            $jsonEvents = $response->getBody()->getContents();

            return roubaixEventsNormalizer($jsonEvents);
        }, function ($reason) {
            //TODO logging
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
    return $client
        ->getAsync(API_BASE_GRANDMIX_JSON, [
            'headers' => [
                'Accept' => 'application/json'
            ]
        ])
        ->then(function ($response) {
            $jsonEvents = $response->getBody()->getContents();

            return grandmixEventsNormalizer($jsonEvents);
        }, function ($reason) {
            //TODO logging
            return [];
        });
}

/**
 * Get all events from various sources
 *
 * @return array
 */
function getAllEvents() {
    $client = new Client();

    $results = Promise\unwrap([
        'tourcoingsEvents' => getTourcoingEvents($client),
        'roubaixEvents' => getRoubaixEvents($client),
        'grandmixEvents' => getGrandmixEvents($client)
    ]);

    return sortEvents(array_merge(
        $results['tourcoingsEvents'],
        $results['roubaixEvents'],
        $results['grandmixEvents']
    ));
}

/**
 * Filter next events according to $day parameter
 *
 * @param array $events
 * @param integer $days
 * @return array
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
 * Filter events in the next hour
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

    return $res->withJson($events);
});

/**
 * Returns next 7 days events
 */
$app->get('/events/7days', function (Request $req, Response $res) {
    $events = getAllEvents();
    $events = filterNextEvents($events);

    return $res->withJson($events);
});

/**
 * Returns events in the next hour
 */
$app->get('/events/now', function (Request $req, Response $res) {
    $events = getAllEvents();
    $events = filterInHourEvents($events);

    return $res->withJson($events);
});

$app->run();