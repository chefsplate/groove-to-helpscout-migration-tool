<?php

require 'vendor/autoload.php';

spl_autoload_register(function ($class) {
    include 'processors/' . $class . '.php';
});

// Parse configurations and define as constants
$ini_array = parse_ini_file("conf/keys.ini");
define("GROOVEHQ_API_KEY", $ini_array['groovehq_client_api_key']);
define("HELPSCOUT_API_KEY", $ini_array['helpscout_client_api_key']);
define("GROOVEHQ_REQUESTS_PER_MINUTE", intval($ini_array['groovehq_rate_limit']));
define("HELPSCOUT_REQUESTS_PER_MINUTE", intval($ini_array['helpscout_rate_limit']));


$users_ini_array = parse_ini_file("conf/user-mapping.ini");


// FIXME: remove after development
$DEBUG_LIMIT = 5;

$requests_processed_this_minute = 0;
$start_of_minute_timestamp = time();
$uploadQueue = array();

// -------
// Acquire
// -------

// TODO: Move acquisition to its own module
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
            // TODO: nicer formatting (maybe a viewer)
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
    } else {
        // don't do anything
    }
    return $response;
}

function addToQueue($jobs_list) {
    global $uploadQueue;
    $uploadQueue = array_merge($uploadQueue, $jobs_list);
}

var_dump($tickets_service->count());
exit();

// ----------------------
// 1. Fetch all customers
// ----------------------
// Customers come first, as the process of creating conversations may create a new customer
$page_number = 1;
$number_customers = 0;

do {
    $response = makeRateLimitedRequest(
        function () use ($customers_service, $page_number) {
            return $customers_service->list(['page' => $page_number, 'per_page' => 50])['customers'];
        },
        CustomerProcessor::getProcessor(),
        GROOVEHQ_REQUESTS_PER_MINUTE);
    echo "Retrieved " . count($response) . " customers from page " . $page_number . " <br>";
    $number_customers += count($response);
    $page_number++;
} while (count($response) > 0 && $page_number <= $DEBUG_LIMIT);
echo "$number_customers customers retrieved.";





// ----------------
// Fetch all agents
// ----------------
$page_number = 1;
$number_agents = 0;

do {
    $response = makeRateLimitedRequest(
        function () use ($agents_service, $page_number) {
            $agents = $agents_service->list()['agents'];
            var_dump($agents);
        },
        AgentProcessor::getProcessor(),
        GROOVEHQ_REQUESTS_PER_MINUTE);
    echo "Retrieved " . count($response) . " agents from page " . $page_number . " <br>";
    $number_agents += count($response);
    $page_number++;
} while (count($response) > 0 && $page_number <= $DEBUG_LIMIT);
echo "$number_agents agents retrieved.";

exit();





// --------------------
// 2. Fetch all tickets
// --------------------
// 
$page_number = 1;
$number_tickets = 0;

do {
    $response = makeRateLimitedRequest(
        function () use ($tickets_service, $page_number) {
            return $tickets_service->list(['page' => $page_number, 'per_page' => 50])['tickets'];
        },
        TicketProcessor::getProcessor(),
        GROOVEHQ_REQUESTS_PER_MINUTE);
    echo "Retrieved " . count($response) . " tickets from page " . $page_number . " <br>";
    $number_tickets += count($response);
    $page_number++;
} while (count($response) > 0 && $page_number <= $DEBUG_LIMIT);
echo "$number_tickets tickets retrieved.";





// -------
// Process
// -------

// TODO: map states and fields of data objects
function processAgents($groove_agents) {

}

function processMessages($groove_messages) {
}

function processTickets($groove_tickets) {
    // statuses for Groove: unread, opened, pending, closed, spam
    // statuses for Help Scout: active, pending, closed, spam
}

// -------
// Publish
// -------

// TODO: move publish to its own module
use HelpScout\ApiClient;

$requests_processed_this_minute = 0;
$start_of_minute_timestamp = time();

// Create customers
$client = null;
try {
    $client = ApiClient::getInstance();
    $client->setKey(HELPSCOUT_API_KEY);
} catch (HelpScout\ApiException $e) {
    // TODO: standardize error messaging interface
    echo "Error creating client";
    echo $e->getMessage();
    print_r($e->getErrors());
    exit();
}

$error_mapping = array();

foreach ($uploadQueue as $model) {
    try {
        $classname = explode('\\', get_class($model));
        if (strcasecmp(end($classname), "Customer") === 0) {
            $response = makeRateLimitedRequest(function () use ($client, $model) {
                $client->createCustomer($model);
            }, null, HELPSCOUT_REQUESTS_PER_MINUTE);
        }
    } catch (HelpScout\ApiException $e) {
        echo $e->getMessage();
        print_r($e->getErrors());
        echo "<br>";
        foreach ($e->getErrors() as $error) {
            $error_mapping[$error['message']] []= $error;
        }
    }
}

var_dump($error_mapping);

// Task breakdown
// TODO: create queue of jobs to update so we don't spam the HelpScout connection
// TODO: determine rate limiting for API and batch jobs according to that ratio
// TODO: execute batches

// TODO: unit tests for email validation; that the correct customer/models were created

// Nice-to-haves
// TODO: generate progress updater
// TODO: wizard for updating php.ini