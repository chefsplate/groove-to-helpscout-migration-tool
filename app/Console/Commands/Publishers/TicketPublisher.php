<?php

namespace App\Console\Commands\Publishers;

use HelpScout\ApiException;

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

            $consoleCommand->createProgressBar(count($conversationsList));

            /* @var $conversation \HelpScout\model\Conversation */
            foreach ($conversationsList as $conversation) {
                try {
                    $client = $consoleCommand->getHelpScoutClient();
                    $createConversationResponse = $consoleCommand->makeRateLimitedRequest(HELPSCOUT, function () use ($client, $conversation) {
                        $client->createConversation($conversation, true); // imported = true to prevent spam!
                    });
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

            if (sizeof($errorMapping) > 0) {
                // TODO: output to a CSV instead or Laravel logger
                $consoleCommand->error(print_r($errorMapping, TRUE));
            }
        };
    }
}