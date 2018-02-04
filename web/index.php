<?php

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

use \GuzzleHttp\Promise;
use \GuzzleHttp\Client;

require '../vendor/autoload.php';

const API_BASE_TOURCOING_RSS = 'https://agenda.tourcoing.fr/flux/rss/';
const API_BASE_ROUBAIX_JSON = 'https://openagenda.com/agendas/9977986/events.json?lang=fr';

const MAX_DESCRIPTION_SIZE = 180;

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
            'public' => [
                'type' => $publicNode->getElementsByTagName('type')[0]->nodeValue,
                'label' => $publicNode->getElementsByTagName('label')[0]->nodeValue
            ],
            'rates' => $ratesArray
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
            'public' => [
                'type' => '',
                'label' => ''
            ],
            'rates' => []
        ];
    }

    return $events;
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
 * Merge Roubaix and Tourcoing events arrays
 *
 * @param [type] $eventsA
 * @param [type] $eventsB
 * @return void
 */
function mergeEvents($eventsA, $eventsB) {
    $events = array_merge($eventsA, $eventsB);

    // order by ASC on startDate
    usort($events, function ($a, $b) {
        return date_compare($a, $b, 'startDate', 'ASC');
    });

    return $events;
}

/**
 * Undocumented function
 *
 * @param [type] $client
 * @return void
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
        });
}

/**
 * Undocumented function
 *
 * @param [type] $client
 * @return void
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
        });
}

/**
 * Undocumented function
 *
 * @param [type] $events
 * @param integer $days
 * @return array
 */
function filterNextEvents($events, $days = 7) {
    $day = new \Moment\Moment();
    $nextEvents = [];

    for ($i = 0; $i < $days; $i += 1) {
        $nextEvents[] = [
            'dayNumber' => (int) $day->getWeekday(),
            'dayName' => ucfirst($day->getWeekdayNameShort()),
            'events' => array_values(array_filter($events, function ($event) use ($day) {
                // $day - $startEvent <= 0 AND $day - $endEvent >= 0
                // meaning the event is started or starts today and isn't ending or ends today
                return $day->from($event['startDate'])->getDays() <= 0 && $day->from($event['endDate'])->getDays() >= 0;
            }))
        ];

        // next day
        $day->addDays(1);
    }

    return $nextEvents;
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
    $client = new Client();

    $results = Promise\unwrap([
        'tourcoingsEvents' => getTourcoingEvents($client),
        'roubaixEvents' => getRoubaixEvents($client)
    ]);

    $events = mergeEvents($results['tourcoingsEvents'], $results['roubaixEvents']);

    return $res->withJson($events);
});

/**
 * Returns next 7 days events
 */
$app->get('/events/7days', function (Request $req, Response $res) {
    $client = new Client();

    $results = Promise\unwrap([
        'tourcoingsEvents' => getTourcoingEvents($client),
        'roubaixEvents' => getRoubaixEvents($client)
    ]);

    $events = mergeEvents($results['tourcoingsEvents'], $results['roubaixEvents']);
    $events = filterNextEvents($events);

    return $res->withJson($events);
});

$app->run();