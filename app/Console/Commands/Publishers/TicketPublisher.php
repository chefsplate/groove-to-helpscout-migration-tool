<?php

namespace App\Console\Commands\Publishers;

use App\Console\Commands\APIHelper;
use App\Console\Commands\Models\HybridConversation;
use App\Console\Commands\SyncCommandBase;
use HelpScout\ApiException;
use HelpScout\model\Conversation;

class TicketPublisher implements PublisherInterface
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
        return function ($conversationsList) use ($consoleCommand) {

            // Publish/create tickets
            // ----------------------

            $errorMapping = array();

            $consoleCommand->createProgressBar(count($conversationsList));

            /* @var $hybridConversation HybridConversation */
            foreach ($conversationsList as $hybridConversation) {
                /* @var $conversation Conversation */
                $conversation = $hybridConversation->getConversation();
                $grooveTicketNumber = $hybridConversation->getGrooveTicketNumber();
                try {
                    $client = $consoleCommand->getHelpScoutClient();
                    $createConversationResponse = $consoleCommand->makeRateLimitedRequest(HELPSCOUT, function () use ($client, $conversation) {
                        $client->createConversation($conversation, true); // imported = true to prevent spam!
                    });
                } catch (ApiException $e) {
                    $createdBy = $conversation->getCreatedBy()->getEmail() ?
                        $conversation->getCreatedBy()->getEmail()
                        : "user #" . $conversation->getCreatedBy()->getId();
                    $consoleCommand->error("Failed to upload HelpScout conversation \"" . $conversation->getSubject()
                        . "\" by " . $createdBy . " at " . $conversation->getCreatedAt() . " (Groove ticket #$grooveTicketNumber). Message was: \n" . APIHelper::formatApiExceptionArray($e));
                    if ($e->getErrors()) {
                        foreach ($e->getErrors() as $error) {
                            $errorMessage = 'Error: [' . $error['property'] . '] ' . $error['message'] . ' (value = ' . print_r($error['value'], TRUE) . ") (Groove ticket #$grooveTicketNumber)";
                            $errorMapping[$error['message']] [] = $errorMessage;
                            $consoleCommand->getProgressBar()->setMessage($errorMessage . str_pad(' ', 20));
                        }
                    } else {
                        $errorMapping[$e->getMessage()] [] = "[" . $conversation->getCreatedAt()->format('c') . "] " . $conversation->getSubject() . " (Groove ticket #$grooveTicketNumber)";
                    }
                } catch (\CurlException $ce) {
                    $errorMessage = "CurlException encountered while publishing Groove ticket #$grooveTicketNumber \"" . $conversation->getSubject() . "\" (created by " . $conversation->getCreatedBy()->getEmail() . " at " . $conversation->getCreatedAt() . ")";
                    $consoleCommand->error($errorMessage . ": " . $ce->getMessage());
                    $errorMapping[$ce->getMessage()] []= $errorMessage;
                } catch (\ErrorException $errex) {
                    $errorMessage = "Exception encountered while publishing Groove ticket #$grooveTicketNumber \"" . $conversation->getSubject() . "\" (created by " . $conversation->getCreatedBy()->getEmail() . " at " . $conversation->getCreatedAt() . ")";
                    $consoleCommand->error($errorMessage . ": " . $errex->getMessage());
                    $errorMapping[$errex->getMessage()] []= $errorMessage;
                } catch (\Exception $ex) {
                    $errorMessage = "Exception encountered while publishing Groove ticket #$grooveTicketNumber \"" . $conversation->getSubject() . "\" (created by " . $conversation->getCreatedBy()->getEmail() . " at " . $conversation->getCreatedAt() . ")";
                    $consoleCommand->error($errorMessage . ": " . $ex->getMessage());
                    $errorMapping[$ex->getMessage()] []= $errorMessage;
                }
                $consoleCommand->getProgressBar()->advance();
            }
            if ($consoleCommand->getProgressBar()->getMaxSteps() === 0) {
                $consoleCommand->getProgressBar()->clear();
            } else {
                $consoleCommand->getProgressBar()->finish();
            }

            if (sizeof($errorMapping) > 0) {
                $filename = 'sync-tickets-' . date('YmdHis');
                $contents = APIHelper::convertErrorMappingArrayToCSVArray($errorMapping);
                APIHelper::exportArrayToCSV($filename, $contents);
                $consoleCommand->warn("\nEncountered " . count($contents) . " errors, which have been exported to $filename.csv (default location: storage/exports)");
            }
        };
    }
}