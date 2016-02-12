<?php

namespace App\Console\Commands\Publishers;

use HelpScout\ApiException;

class CustomerPublisher implements PublisherInterface
{
    public static function getPublisher($consoleCommand)
    {
        /**
         * @param $customersList array
         * @return array
         */
        return function ($customersList) use ($consoleCommand) {
            // Publish/create customers
            // ------------------------

            $errorMapping = array();

            $consoleCommand->createProgressBar(count($customersList));

            /* @var $customer \HelpScout\model\Customer */
            foreach ($customersList as $customer) {
                try {
                    $client = $consoleCommand->getHelpScoutClient();
                    $helpscoutCreateCustomerResponse = $consoleCommand->makeRateLimitedRequest(HELPSCOUT, function () use ($client, $customer) {
                        $client->createCustomer($customer);
                    });
                } catch (ApiException $e) {
                    foreach ($e->getErrors() as $error) {
                        $errorMapping[$error['message']] [] = "[" . $error['property'] . "] " . $error['message'] . ": " . $error['value'];
                        $consoleCommand->getProgressBar()->setMessage('Error: [' . $error['property'] . '] ' . $error['message'] . ' (' . $error['value'] . ')' . str_pad(' ', 20));
                    }
                }
                $consoleCommand->getProgressBar()->advance();
            }
            $consoleCommand->getProgressBar()->finish();

            // TODO: output to a CSV instead
            $consoleCommand->error(print_r($errorMapping, TRUE));
        };
    }
}