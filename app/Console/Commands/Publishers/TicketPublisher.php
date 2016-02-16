<?php

namespace App\Console\Commands\Publishers;

use App\Console\Commands\SyncCommandBase;
use HelpScout\ApiException;

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
                } catch (\CurlException $ce) {
                    $errorMessage = "CurlException encountered for ticket \"" . $conversation->getSubject() . "\" (created by " . $conversation->getCreatedBy()->getEmail() . " at " . $conversation->getCreatedAt() . ")";
                    $consoleCommand->error($errorMessage . ": " . $ce->getMessage());
                    $errorMapping[$ce->getMessage()] []= $errorMessage;
                } catch (\ErrorException $errex) {
                    $errorMessage = "Exception encountered for ticket \"" . $conversation->getSubject() . "\" (created by " . $conversation->getCreatedBy()->getEmail() . " at " . $conversation->getCreatedAt() . ")";
                    $consoleCommand->error($errorMessage . ": " . $errex->getMessage());
                    $errorMapping[$errex->getMessage()] []= $errorMessage;
                } catch (\Exception $ex) {
                    $errorMessage = "Exception encountered for ticket \"" . $conversation->getSubject() . "\" (created by " . $conversation->getCreatedBy()->getEmail() . " at " . $conversation->getCreatedAt() . ")";
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
                // TODO: output to a CSV instead or Laravel logger
                $consoleCommand->error(print_r($errorMapping, TRUE));
            }
        };
    }
}