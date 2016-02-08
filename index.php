<?php

require 'vendor/autoload.php';

spl_autoload_register(function ($class) {
    include 'Processors/' . $class . '.php';
});


// FIXME: remove after development
$DEBUG_LIMIT = 5;


$uploadQueue = array();

// ---------------------
// Acquire (and process)
// ---------------------

// TODO: Move acquisition to its own module


$agents_service = $gh->agents();

$messages_service = $gh->messages();
$tickets_service = $gh->tickets();
$mailboxes_service = $gh->mailboxes();
$groups_service = $gh->groups();





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





// ---------------------------------
// 2. Fetch all tickets and messages
// ---------------------------------
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
// Publish
// -------

// TODO: move publish to its own module



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
// TODO: unit tests for email validation; that the correct customer/models were created; unit testing for ini file loading

// Nice-to-haves
// TODO: generate progress updater
// TODO: wizard for updating php.ini?