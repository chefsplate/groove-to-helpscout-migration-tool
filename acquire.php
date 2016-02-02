<?php

require 'vendor/autoload.php';

// Parse configurations and define as constants
$ini_array = parse_ini_file("conf/keys.ini");
define("GROOVEHQ_API_KEY", $ini_array['groovehq_client_api_key']);
define("HELPSCOUT_API_KEY", $ini_array['helpscout_client_api_key']);

$gh = new \GrooveHQ\Client(GROOVEHQ_API_KEY);

//$tickets = $gh->tickets();
//$customers = $gh->customers();
//
//$response = $tickets->list(['state' => 'unread']);
//$unread_tickets = $response['tickets'];
//
//$response = $tickets->find(['ticket_number' => '1']);
//$first_ticket = $response['ticket'];
//
//$response = $tickets->state(['ticket_number' => '1']);
//$first_ticket_state = $response['state'];
//
//$response = $tickets->update(['ticket_number' => '1', 'state' => 'opened']);
//
//$response = $customers->list();
//$customers = $response['customers'];