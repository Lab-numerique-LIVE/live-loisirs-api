<?php

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

require '../vendor/autoload.php';

const API_BASE_TOURCOING_RSS = 'https://agenda.tourcoing.fr/flux/rss/';

/**
 *
 * @param  String $xmlEventsString
 * @return Array
 */
function xmlEventsToArray($xmlEventsString)
{
    $events = [];

    $dom = new DOMDocument;
    $dom->loadXML($xmlEventsString);

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

        // build event object
        $events[] = [
            'title' => $eventNode->getElementsByTagName('title')[0]->nodeValue,
            'description' => $eventNode->getElementsByTagName('description')[0]->nodeValue,
            'url' => $eventNode->getElementsByTagName('link')[0]->nodeValue,
            'image' => $eventNode->getElementsByTagName('enclosure')[0]->getAttribute('url'),
            'category' => $eventNode->getElementsByTagName('category')[0]->nodeValue,
            'startDate' => $eventNode->getElementsByTagName('date_start')[0]->nodeValue,
            'endDate' => $eventNode->getElementsByTagName('date_end')[0]->nodeValue,
            'location' => [
                'latitude' => (float) $locationNode->getAttribute('latitude'),
                'longitude' => (float) $locationNode->getAttribute('longitude'),
                'name' => $locationNode->getElementsByTagName('name')[0]->nodeValue,
                'address' => $locationNode->getElementsByTagName('address')[0]->nodeValue,
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
 * @param [type] $client
 * @return void
 */
function getTourcoingEvents($client) {
    $xmlEvents = $client
        ->get(API_BASE_TOURCOING_RSS, [
            'headers' => [
                'Accept' => 'application/rss+xml'
            ]
        ])
        ->getBody()
        ->getContents();

    return xmlEventsToArray($xmlEvents);
}

$app = new \Slim\App;

// CORS
$app->add(new \CorsSlim\CorsSlim());

/**
 * Return all events
 */
$app->get('/events', function (Request $req, Response $res) {
    $client = new \GuzzleHttp\Client();

    $events = getTourcoingEvents($client);

    return $res->withJson($events);
});

/**
 * Return a station by `id`
 */
$app->get('/stations/{id}', function (Request $req, Response $res) {
    $client = new \GuzzleHttp\Client();

    $xmlStation = $client
        ->get(API_BASE . '/xml-station.aspx', [
            'query' => [
                'borne' => $req->getAttribute('id')
            ],
            'headers' => [
                'Accept' => 'application/xml'
            ]
        ])
        ->getBody()
        ->getContents();

    $station = xmlStationToJson($xmlStation);

    return $res->withJson($station);
});


$app->run();