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

class TicketPublisher implements PublisherInterface
{
    public static function getPublisher($consoleCommand)
    {
        /**
         * @param $conversationsList array
         * @return array
         */
        return function ($conversationsList) use ($consoleCommand) {

            // Publish/create tickets
            // ----------------------

            $errorMapping = array();

            $consoleCommand->info("Uploading tickets to HelpScout...");

            $consoleCommand->createProgressBar(count($conversationsList));

            /* @var $conversation \HelpScout\model\Conversation */
            foreach ($conversationsList as $conversation) {
                try {
                    $client = $consoleCommand->getHelpScoutClient();
                    $createConversationResponse = $consoleCommand->makeRateLimitedRequest(HELPSCOUT, function () use ($client, $conversation) {
                        $client->createConversation($conversation, true); // imported = true to prevent spam!
                    }, null, null);
                } catch (ApiException $e) {
                    if ($e->getErrors()) {
                        foreach ($e->getErrors() as $error) {
                            $errorMapping[$error['message']] [] = $error;
                            $consoleCommand->getProgressBar()->setMessage('Error: [' . $error['property'] . '] ' . $error['message'] . ' (' . $error['value'] . ')' . str_pad(' ', 20));
                        }
                    } else {
                        $errorMapping[$e->getMessage()] [] = "[" . $conversation->getCreatedAt()->format('c') . "] " . $conversation->getSubject();
                    }

                }
                $consoleCommand->getProgressBar()->advance();
            }
            $consoleCommand->getProgressBar()->finish();
            $consoleCommand->info("\nCompleted uploading tickets to HelpScout.");

            // TODO: output to a CSV instead
            $consoleCommand->error(print_r($errorMapping, TRUE));
        };
    }
}