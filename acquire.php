<?php

require 'vendor/autoload.php';

// Parse configurations and define as constants
$ini_array = parse_ini_file("conf/keys.ini");
define("GROOVEHQ_API_KEY", $ini_array['groovehq_client_api_key']);
define("HELPSCOUT_API_KEY", $ini_array['helpscout_client_api_key']);

$gh = new \GrooveHQ\Client(GROOVEHQ_API_KEY);

$agents_service = $gh->agents();
$customers_service = $gh->customers();
$messages_service = $gh->messages();
$tickets_service = $gh->tickets();

$response = $tickets_service->list(['state' => 'unread']);
$unread_tickets = $response['tickets'];

$response = $tickets_service->find(['ticket_number' => '8119']);
$first_ticket = $response['ticket'];

$response = $tickets_service->state(['ticket_number' => '1']);
$first_ticket_state = $response['state'];

$response = $tickets_service->update(['ticket_number' => '1', 'state' => 'opened']);

$response = $customers_service->list();
$customers = $response['customers'];


// TODO: acquire the list of tickets, customers, agents, messages and tickets here
// TODO: create queue of jobs to update so we don't spam the HelpScout connection
// TODO: determine rate limiting for API and batch jobs according to that ratio
// TODO: execute batches

// Nice-to-haves
// TODO: generate progress updater
// TODO: wizard for updating php.ini