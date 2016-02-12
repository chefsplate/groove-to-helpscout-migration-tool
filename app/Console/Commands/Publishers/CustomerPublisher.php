<?php

namespace App\Console\Commands\Publishers;

use App\Console\Commands\APIHelper;
use App\Console\Commands\Processors\Exceptions\ValidationException;
use App\Console\Commands\Publishers\PublisherInterface;
use App\Console\Commands\SyncCommandBase;
use DateTime;
use finfo;
use HelpScout\ApiException;
use HelpScout\Collection;
use HelpScout\model\Attachment;
use HelpScout\model\Conversation;
use HelpScout\model\ref\PersonRef;
use HelpScout\model\thread\AbstractThread;
use HelpScout\model\thread\Customer;
use HelpScout\model\thread\Note;

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
                    }, null, null);
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