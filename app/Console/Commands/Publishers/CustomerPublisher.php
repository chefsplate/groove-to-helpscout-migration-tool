<?php

namespace App\Console\Commands\Publishers;

use App\Console\Commands\APIHelper;
use App\Console\Commands\SyncCommandBase;
use HelpScout\ApiException;

class CustomerPublisher implements PublisherInterface
{
    private static $publisher;

    public static function getPublisher($consoleCommand)
    {
        if (null === static::$publisher) {
            static::$publisher = self::generatePublisher($consoleCommand);
        }

        return static::$publisher;
    }

    /**
     * @param $consoleCommand SyncCommandBase
     * @return \Closure
     */
    private static function generatePublisher($consoleCommand)
    {
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
//                    $customerEmails = array_map(function($emailEntry) {
//                        /* @var $emailEntry \HelpScout\model\customer\EmailEntry */
//                        return $emailEntry->getValue();
//                    }, $customer->getEmails());
//                    $consoleCommand->error("Failed to upload HelpScout customer (" . implode(',', $customerEmails) . ")" . ". Message was: " . APIHelper::formatApiExceptionArray($e));
                    foreach ($e->getErrors() as $error) {
                        $errorMapping[$error['message']] [] = "[" . $error['property'] . "] " . $error['message'] . ": " . $error['value'];
                        $consoleCommand->getProgressBar()->setMessage('Error: [' . $error['property'] . '] ' . $error['message'] . ' (' . $error['value'] . ')' . str_pad(' ', 20));
                    }
                }
                $consoleCommand->getProgressBar()->advance();
            }

            if ($consoleCommand->getProgressBar()->getMaxSteps() === 0) {
                $consoleCommand->getProgressBar()->clear();
            } else {
                $consoleCommand->getProgressBar()->finish();
            }

            if (sizeof($errorMapping) > 0) {
                $filename = 'sync-customers-' . date('YmdHis');
                $contents = APIHelper::convertErrorMappingArrayToCSVArray($errorMapping);
                APIHelper::exportArrayToCSV($filename, $contents);
                $consoleCommand->warn("\nEncountered " . count($contents) . " errors, which have been exported to $filename.csv (default location: storage/exports)");
            }
        };
    }
}