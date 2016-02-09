<?php

namespace App\Console\Commands\Processors;

use App\Console\Commands\SyncCommandBase;
use finfo;
use HelpScout\ApiException;
use HelpScout\Collection;
use HelpScout\model\Attachment;
use HelpScout\model\Conversation;
use HelpScout\model\Mailbox;
use HelpScout\model\ref\PersonRef;
use HelpScout\model\thread\AbstractThread;
use HelpScout\model\thread\Customer;
use HelpScout\model\thread\Note;
use HelpScout\model\User;

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2016-02-04
 * Time: 1:29 PM
 *
 */
class TicketProcessor implements ProcessorInterface
{
    // We need a mapping of agents to MailboxRefs
    /**
     * @param $consoleCommand SyncCommandBase
     */
    private static function retrieveHelpscoutMailboxes($consoleCommand)
    {
        $pageNumber = 1;
        $helpscoutMailboxes = array();
        try {
            do {
                /* @var $response Collection */
                $response = $consoleCommand->makeRateLimitedRequest(function () use ($consoleCommand, $pageNumber) {
                    return $consoleCommand->getHelpScoutClient()->getMailboxes(['page' => $pageNumber]);
                }, null, HELPSCOUT);
                $helpscoutMailboxes = array_merge($helpscoutMailboxes, $response->getItems());
                $pageNumber++;
            } while ($response->hasNextPage());
        } catch (ApiException $e) {
            echo $e->getMessage();
            print_r($e->getErrors());
        }

        return $helpscoutMailboxes;
    }

    /**
     * @param $consoleCommand SyncCommandBase
     */
    private static function retrieveHelpscoutUsers($consoleCommand)
    {
        $pageNumber = 1;
        $helpscoutUsers = array();
        try {
            do {
                /* @var $response Collection */
                $response = $consoleCommand->makeRateLimitedRequest(function () use ($consoleCommand, $pageNumber) {
                    return $consoleCommand->getHelpScoutClient()->getUsers(['page' => $pageNumber]);
                }, null, HELPSCOUT);
                $helpscoutUsers = array_merge($helpscoutUsers, $response->getItems());
                $pageNumber++;
            } while ($response->hasNextPage());
        } catch (ApiException $e) {
            echo $e->getMessage();
            print_r($e->getErrors());
        }

        return $helpscoutUsers;
    }

    /**
     * @param $mailboxes array
     * @param $emailAddress string
     * @return Mailbox
     */
    private static function findMatchingMailbox($mailboxes, $emailAddress) {
        /* @var $mailbox Mailbox */
        foreach ($mailboxes as $mailbox) {
            if (strcasecmp($mailbox->getEmail(), $emailAddress) === 0) {
                return $mailbox;
            }
        }
        return null;
    }

    /**
     * @param $users array
     * @param $emailAddress string
     * @return User
     */
    private static function findMatchingUserWithEmail($users, $emailAddress)
    {
        /* @var $user User */
        foreach ($users as $user) {
            if (strcasecmp($user->getEmail(), $emailAddress) === 0) {
                return $user;
            }
        }
        return null;
    }

    /**
     * @param $consoleCommand SyncCommandBase
     * @param array $servicesMapping
     * @return \Closure
     */
    public static function getProcessor($consoleCommand, $servicesMapping)
    {
        /**
         * @param $ticketsList
         * @return array
         */
        return function ($ticketsList) use ($consoleCommand, $servicesMapping) {
            $processedTickets = array();

            $helpscoutMailboxes = self::retrieveHelpscoutMailboxes($consoleCommand);
            $helpscoutUsers = self::retrieveHelpscoutUsers($consoleCommand);

            foreach ($ticketsList as $grooveTicket) {
                $ticketsService = $servicesMapping['ticketsService'];

                try {
                    $grooveAgent = $consoleCommand->makeRateLimitedRequest(function () use ($ticketsService, $grooveTicket) {
                        return $ticketsService->assignee(['ticket_number' => $grooveTicket['number']])['agent'];
                    }, null, GROOVE);

                    $conversation = new Conversation();
                    $conversation->setType('email');

                    // mailbox
                    $assignedMailboxEmail = $grooveAgent;
                    if (!$grooveAgent) {
                        $assignedMailboxEmail = config('services.helpscout.default_mailbox');
                    }
                    $conversation->setMailbox(
                        self::findMatchingMailbox($helpscoutMailboxes, $assignedMailboxEmail)
                            ->toRef()
                    );
                    if (!$conversation->getMailbox()) {
                        $exception = new ApiException("Mailbox not found: $assignedMailboxEmail");
                        $exception->setErrors(
                            array(
                                [
                                    'property' => 'email',
                                    'message' => 'Mailbox not found',
                                    'value' => $assignedMailboxEmail
                                ]
                            ));
                        throw $exception;
                    }

                    // CustomerRef
                    $matches = array();
                    if (isset($grooveTicket['links']['customer']) && preg_match('@^https://api.groovehq.com/v1/customers/(.*)@i',
                            $grooveTicket['links']['customer']['href'], $matches) === 1) {
                        $conversation->setCustomer(new PersonRef((object) array('email' => $matches[0])));
                    } else {
                        throw new ApiException("No customer defined for ticket: " . $grooveTicket['number']);
                    }

                    // CreatedAt
                    $conversation->setCreatedAt($grooveTicket['created_at']);

                    $conversation->setThreads(self::retrieveThreadsForGrooveTicket($consoleCommand, $servicesMapping, $grooveTicket, $helpscoutUsers));

                    switch ($grooveTicket['state']) {
                        case 'unread':
                        case 'opened':
                            $conversation->setStatus('active');
                            break;
                        case 'pending':
                            $conversation->setStatus('pending');
                            break;
                        case 'closed':
                            $conversation->setStatus('closed');
                            break;
                        case 'spam':
                            $conversation->setStatus('spam');
                            break;
                        default:
                            $consoleCommand->error("Unknown state provided: " . $grooveTicket['state']);
                            break;
                    }

                    $processedTickets [] = $conversation;
                } catch (ApiException $e) {
                    // TODO: output this to console instead of dumping
                    echo $e->getMessage();
                    print_r($e->getErrors());
                }
            }
            return $processedTickets;
        };
    }

    /**
     * @param $consoleCommand SyncCommandBase
     * @param $servicesMapping array
     * @param $grooveTicket array
     * @param $helpscoutUsers array
     * @return array
     */
    private static function retrieveThreadsForGrooveTicket($consoleCommand, $servicesMapping, $grooveTicket, $helpscoutUsers)
    {
        $pageNumber = 1;
        $helpscoutThreads = array();
        $messagesService = $servicesMapping['messagesService'];
        try {
            do {
                /* @var $response array */
                $response = $consoleCommand->makeRateLimitedRequest(function () use ($consoleCommand, $pageNumber, $messagesService, $grooveTicket) {
                    return $messagesService->messages(['page' => $pageNumber, 'per_page' => 50, 'ticket_number' => $grooveTicket['number']]);
                }, null, GROOVE);

                foreach($response['messages'] as $grooveMessage) {
                    /* @var $thread AbstractThread */
                    $thread = null;
                    if ($grooveMessage['note']) {
                        $thread = new Note();
                        $thread->setType('note');
                    } else {
                        $thread = new Customer();
                        $thread->setType('customer');
                    }
                    $thread->setBody($grooveMessage['body']);
                    $thread->setStatus($grooveMessage['status']);
                    $thread->setCreatedAt($grooveMessage['created_at']);
                    $thread->setStatus('nochange');

                    // CreatedBy is a PersonRef - type must be 'user' for messages or notes
                    // Type must be 'customer' for customer threads
                    // Chat or phone types can be either 'user' or 'customer'
                    // 'user' types require an ID field
                    // 'customer' types require either an ID or email
                    list($authorEmailAddress, $addressType) = self::extractEmailAddressFromGrooveLink($grooveTicket['links']['author']['href'], 'author');
                    $id = null;
                    if (strcasecmp($addressType, 'customer') === 0) {
                        /* @var $response Collection */
                        $response = $consoleCommand->makeRateLimitedRequest(function () use ($consoleCommand, $authorEmailAddress) {
                            return $consoleCommand->getHelpScoutClient()->searchCustomersByEmail($authorEmailAddress);
                        }, null, GROOVE);
                        if ($response->getCount() > 0) {
                            /* @var $firstItem \HelpScout\model\Customer */
                            $firstItem = $response->getItems()[0];
                            $id = $firstItem->getId();
                        }
                    } else {
                        $matchingUser = self::findMatchingUserWithEmail($helpscoutUsers, $authorEmailAddress);
                        if (!$matchingUser) {
                            throw new ApiException("No corresponding user found for: $authorEmailAddress");
                        }
                    }
                    $personRef = new PersonRef((object) array(
                        'type' => ($grooveMessage['note'] ? 'user' : 'customer'),
                        'email' => $authorEmailAddress,
                        'id' => $id
                    ));
                    $thread->setCreatedBy($personRef);

                    // To field
                    list($recipientEmailAddress, $addressType) = self::extractEmailAddressFromGrooveLink($grooveTicket['links']['recipient']['href'], 'recipient');
                    if ($recipientEmailAddress) {
                        $thread->setToList(array($recipientEmailAddress));
                    }

                    $thread->setAttachments(self::retrieveAttachmentsForGrooveMessage($consoleCommand, $servicesMapping, $grooveMessage));

                    $helpscoutThreads []= $thread;
                }
                $pageNumber++;
            } while ($pageNumber < $response['meta']['pagination']['total_pages']);
        } catch (ApiException $e) {
            echo $e->getMessage();
            print_r($e->getErrors());
        }

        return $helpscoutThreads;
    }

    /**
     * @param $grooveLink
     * @param $personType
     * @return mixed
     * @throws ApiException
     */
    private static function extractEmailAddressFromGrooveLink($grooveLink, $personType)
    {
        $matches = array();
        if (preg_match('@^https://api.groovehq.com/v1/customers/(.*)@i',
                $grooveLink, $matches) === 1
        ) {
            return array($matches[0], 'customer');
        } elseif (preg_match('@^https://api.groovehq.com/v1/agents/(.*)@i',
                $grooveLink, $matches) === 1
        ) {
            return array($matches[0], 'agent');
        }
        throw new ApiException("No $personType defined for Groove link: " . $grooveLink);
    }

    /**
     * @param $consoleCommand SyncCommandBase
     * @param $servicesMapping array
     * @param $grooveTicket array
     * @return array
     */
    private static function retrieveAttachmentsForGrooveMessage($consoleCommand, $servicesMapping, $grooveMessage)
    {
        $messagesService = $servicesMapping['messagesService'];
        $grooveMessageId = null;
        $matches = array();
        $helpscoutAttachments = array();
        if (preg_match('@^https://api.groovehq.com/v1/attachments?message=(.*)@i',
                $grooveMessage['links']['attachments']['href'], $matches) === 1
        ) {
            $grooveMessageId = $matches[0];
        } else {
            return $helpscoutAttachments;
        }

        $attachments = $consoleCommand->makeRateLimitedRequest(function () use ($consoleCommand, $messagesService, $grooveMessageId) {
            return $messagesService->attachments(['message' => $grooveMessageId])['attachments'];
        }, null, GROOVE);

        foreach($attachments as $grooveAttachment) {
            $helpscoutAttachment = new Attachment();
            $helpscoutAttachment->setFileName($grooveAttachment['filename']);

            $buffer = file_get_contents($grooveAttachment['url']);
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($buffer);

            $helpscoutAttachment->setMimeType($mimeType);
            $helpscoutAttachment->setData($buffer);
            $helpscoutAttachments []= $helpscoutAttachment;
        }

        return $helpscoutAttachments;
    }
}