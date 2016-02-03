<?php

require 'vendor/autoload.php';

// Parse configurations and define as constants
$ini_array = parse_ini_file("conf/keys.ini");
define("GROOVEHQ_API_KEY", $ini_array['groovehq_client_api_key']);
define("HELPSCOUT_API_KEY", $ini_array['helpscout_client_api_key']);
define("GROOVEHQ_REQUESTS_PER_MINUTE", intval($ini_array['groovehq_rate_limit']));
define("HELPSCOUT_REQUESTS_PER_MINUTE", intval($ini_array['helpscout_rate_limit']));

$requests_processed_this_minute = 0;
$start_of_minute_timestamp = time();
$uploadQueue = array();

// -------
// Acquire
// -------

$gh = new \GrooveHQ\Client(GROOVEHQ_API_KEY);

$agents_service = $gh->agents();
$customers_service = $gh->customers();
$messages_service = $gh->messages();
$tickets_service = $gh->tickets();

function makeRateLimitedRequest($requestFunction, $processFunction = null, $rate_limit) {
    global $requests_processed_this_minute, $start_of_minute_timestamp;
    if ($requests_processed_this_minute >= $rate_limit) {
        $seconds_to_sleep = 60 - (time() - $start_of_minute_timestamp);
        if ($seconds_to_sleep > 0) {
            // TODO: output in viewer
            echo "Rate limit reached. Waiting $seconds_to_sleep seconds. <br>";
            sleep($seconds_to_sleep);
        }
        $start_of_minute_timestamp = time();
        $requests_processed_this_minute = 0;
    } elseif (time() - $start_of_minute_timestamp > 60) {
        $start_of_minute_timestamp = time();
        $requests_processed_this_minute = 0;
    }
    $response = $requestFunction();
    $requests_processed_this_minute++;
    if ($processFunction != null) {
        /** @var callable $processFunction */
        addToQueue($processFunction($response));
    }
    return $response;
}

function addToQueue($jobs_list) {
    global $uploadQueue;
    $uploadQueue = array_merge($uploadQueue, $jobs_list);
}

// Fetch all tickets
$page_number = 1;
do {
    $response = makeRateLimitedRequest(function () use ($tickets_service, $page_number) {
        return $tickets_service->list(['page' => $page_number, 'per_page' => 50])['tickets'];
    }, null, GROOVEHQ_REQUESTS_PER_MINUTE);
    echo "Retrieved " . count($response) . " tickets from page " . $page_number . " <br>";
    $page_number++;
} while (count($response) > 0);

$unread_tickets = $response['tickets'];


$response = $tickets_service->find(['ticket_number' => '8119']);
$first_ticket = $response['ticket'];

$response = $customers_service->list();
$customers = $response['customers'];

// TODO: acquire the list of tickets, customers, agents, messages and tickets here

// -------
// Process
// -------

// TODO: map states and fields of data objects
function processAgents($groove_agents) {

}

function processCustomers($groove_customers) {
}

function processMessages($groove_messages) {
}

function processTickets($groove_tickets) {
// statuses for Groove:
    // statuses for Help Scout: active, pending, closed, spam
}

// -------
// Publish
// -------

function processPublishJobQueue() {
    global $uploadQueue;
    foreach ($uploadQueue as $job) {
        $job->publish();
    }
}


// Task breakdown
// TODO: create queue of jobs to update so we don't spam the HelpScout connection
// TODO: determine rate limiting for API and batch jobs according to that ratio
// TODO: execute batches

// Nice-to-haves
// TODO: generate progress updater
// TODO: wizard for updating php.ini