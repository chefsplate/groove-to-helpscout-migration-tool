<?php

namespace App\Console\Commands\Processors;

use App\Console\Commands\Processors\Exceptions\ValidationException;
use App\Console\Commands\SyncCommandBase;
use DateTime;
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
    /**
     * @param $consoleCommand SyncCommandBase
     */
    private static function retrieveHelpscoutMailboxes($consoleCommand)
    {
        $pageNumber = 1;
        $cumulativeHelpscoutMailboxes = array();
        try {
            do {
                /* @var $helpscoutMailboxesResponse Collection */
                $helpscoutMailboxesResponse = $consoleCommand->makeRateLimitedRequest(function () use ($consoleCommand, $pageNumber) {
                    return $consoleCommand->getHelpScoutClient()->getMailboxes(['page' => $pageNumber]);
                }, null, HELPSCOUT);
                $cumulativeHelpscoutMailboxes = array_merge($cumulativeHelpscoutMailboxes, $helpscoutMailboxesResponse->getItems());
                $pageNumber++;
            } while ($helpscoutMailboxesResponse->hasNextPage());
        } catch (ApiException $e) {
            echo $e->getMessage();
            print_r($e->getErrors());
        }

        return $cumulativeHelpscoutMailboxes;
    }

    /**
     * @param $consoleCommand SyncCommandBase
     */
    private static function retrieveHelpscoutUsers($consoleCommand)
    {
        $pageNumber = 1;
        $cumulativeHelpscoutUsers = array();
        try {
            do {
                /* @var $helpscoutUsersResponse Collection */
                $helpscoutUsersResponse = $consoleCommand->makeRateLimitedRequest(function () use ($consoleCommand, $pageNumber) {
                    return $consoleCommand->getHelpScoutClient()->getUsers(['page' => $pageNumber]);
                }, null, HELPSCOUT);
                $cumulativeHelpscoutUsers = array_merge($cumulativeHelpscoutUsers, $helpscoutUsersResponse->getItems());
                $pageNumber++;
            } while ($helpscoutUsersResponse->hasNextPage());
        } catch (ApiException $e) {
            echo $e->getMessage();
            print_r($e->getErrors());
        }

        return $cumulativeHelpscoutUsers;
    }

    /**
     * @param $mailboxes array
     * @param $mailboxEmail string
     * @return Mailbox
     */
    private static function findMatchingMailboxByEmail($mailboxes, $mailboxEmail) {
        /* @var $mailbox Mailbox */
        foreach ($mailboxes as $mailbox) {
            if (strcasecmp($mailbox->getEmail(), $mailboxEmail) === 0) {
                return $mailbox;
            }
        }
        return null;
    }

    /**
     * @param $mailboxes array
     * @param $mailboxName string
     * @return Mailbox
     */
    private static function findMatchingMailboxByName($mailboxes, $mailboxName) {
        /* @var $mailbox Mailbox */
        foreach ($mailboxes as $mailbox) {
            if (strcasecmp($mailbox->getName(), $mailboxName) === 0) {
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

            // Validation check: Ensure each mailbox in Groove maps to a HelpScout mailbox
            $consoleCommand->info("Validation check: ensuring each mailbox in Groove maps to a HelpScout mailbox");
            $helpscoutMailboxes = self::retrieveHelpscoutMailboxes($consoleCommand);
            $helpscoutUsers = self::retrieveHelpscoutUsers($consoleCommand);

            $mailboxesService = $servicesMapping['mailboxesService'];
            $grooveMailboxes = $consoleCommand->makeRateLimitedRequest(function () use ($mailboxesService) {
                return $mailboxesService->mailboxes()['mailboxes'];
            }, null, GROOVE);

            $hasErrors = false;

            foreach($grooveMailboxes as $grooveMailbox) {
                $grooveMailboxName = $grooveMailbox['name'];
                if (!($helpscoutMailbox = self::findMatchingMailboxByName($helpscoutMailboxes, $grooveMailboxName))) {
                    $consoleCommand->error('Missing corresponding HelpScout mailbox named: ' . $grooveMailboxName);
                    $hasErrors = true;
                } else {
                    $consoleCommand->info("[ OK ] " . $grooveMailboxName . " mapped to " . $helpscoutMailbox->getEmail());
                }
            }

            // Validation check: Ensure each agent has a corresponding user in HelpScout
            $consoleCommand->info("\nValidation check: ensuring each Groove agent maps to a corresponding HelpScout user");
            $agentsService = $servicesMapping['agentsService'];
            $grooveAgents = $consoleCommand->makeRateLimitedRequest(function () use ($agentsService) {
                return $agentsService->list()['agents'];
            }, null, GROOVE);

            foreach($grooveAgents as $grooveAgent) {
                $grooveAgentEmail = $grooveAgent['email'];
                if (!($helpscoutUser = self::findMatchingUserWithEmail($helpscoutUsers, $grooveAgentEmail))) {
                    $consoleCommand->error('Missing corresponding HelpScout user for email: ' . $grooveAgentEmail);
                    $hasErrors = true;
                } else {
                    $consoleCommand->info("[ OK ] " . $grooveAgentEmail . " mapped to HelpScout user " . $helpscoutUser->getFullName() . " (" . $helpscoutUser->getId() . ")");
                }
            }

            if ($hasErrors) {
                $consoleCommand->error("\nValidation failed. Please correct the above errors, otherwise we cannot proceed.");
                exit();
            }

            foreach ($ticketsList as $grooveTicket) {
                try {
                    $conversation = new Conversation();
                    $conversation->setType('email');

                    // mailbox
                    $mailboxName = $grooveTicket['mailbox'];
                    $assignedMailbox = self::findMatchingMailboxByName($helpscoutMailboxes, $mailboxName);
                    if (!$assignedMailbox) {
                        $mailboxRef = self::findMatchingMailboxByEmail($helpscoutMailboxes, config('services.helpscout.default_mailbox'))->toRef();
                    } else {
                        $mailboxRef = $assignedMailbox->toRef();
                    }
                    $conversation->setMailbox($mailboxRef);
                    if (!$conversation->getMailbox()) {
                        $exception = new ApiException("Mailbox not found in HelpScout: " . $mailboxName);
                        $exception->setErrors(
                            array(
                                [
                                    'property' => 'mailbox',
                                    'message' => 'Mailbox not found',
                                    'value' => $mailboxName
                                ]
                            ));
                        throw $exception;
                    }

                    $tags = $grooveTicket['tags'];
                    if ($tags && count($tags) > 0) {
                        $conversation->setTags($tags);
                    }

                    // CustomerRef
                    $matches = array();
                    if (isset($grooveTicket['links']['customer']) && preg_match('@^https://api.groovehq.com/v1/customers/(.*)@i',
                            $grooveTicket['links']['customer']['href'], $matches) === 1) {
                        $customerEmail = $matches[1];
                        if (filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                            $conversation->setCustomer(new PersonRef((object) array('email' => $customerEmail)));
                        } else {
                            $customersService = $servicesMapping['customersService'];
                            $grooveCustomer = $consoleCommand->makeRateLimitedRequest(function () use ($customersService, $customerEmail) {
                                return $customersService->find(['customer_email' => $customerEmail])['customer'];
                            }, null, GROOVE);
                            $conversation->setCustomer(new PersonRef((object) array('email' => $grooveCustomer['email'])));
                        }

                    } else {
                        throw new ApiException("No customer defined for ticket: " . $grooveTicket['number']);
                    }

                    // CreatedAt
                    $conversation->setCreatedAt(new DateTime($grooveTicket['created_at']));

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
                /* @var $grooveMessages array */
                $grooveMessages = $consoleCommand->makeRateLimitedRequest(function () use ($consoleCommand, $pageNumber, $messagesService, $grooveTicket) {
                    return $messagesService->list(['page' => $pageNumber, 'per_page' => 50, 'ticket_number' => $grooveTicket['number']]);
                }, null, GROOVE);

                foreach($grooveMessages['messages'] as $grooveMessage) {
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
                    $thread->setCreatedAt(new DateTime($grooveMessage['created_at']));
                    $thread->setStatus('nochange');

                    // CreatedBy is a PersonRef - type must be 'user' for messages or notes
                    // Type must be 'customer' for customer threads
                    // Chat or phone types can be either 'user' or 'customer'
                    // 'user' types require an ID field
                    // 'customer' types require either an ID or email
                    list($authorEmailAddress, $addressType) = self::extractEmailAddressFromGrooveLink($grooveMessage['links']['author']['href'], 'author');
                    $id = null;
                    if (strcasecmp($addressType, 'customer') === 0) {
                        /* @var $response Collection */
                        $helpscoutCustomer = $consoleCommand->makeRateLimitedRequest(function () use ($consoleCommand, $authorEmailAddress) {
                            return $consoleCommand->getHelpScoutClient()->searchCustomersByEmail($authorEmailAddress);
                        }, null, GROOVE);
                        // TODO: the customer could be blank - we need to fetch extra details from Groove.
                        // May not be a bad idea to always retrieve the Groove customer
                        if ($helpscoutCustomer->getCount() > 0) {
                            /* @var $firstItem \HelpScout\model\Customer */
                            $firstItem = $helpscoutCustomer->getItems()[0];
                            $id = $firstItem->getId();
                        }
                    } else {
                        $matchingUser = self::findMatchingUserWithEmail($helpscoutUsers, $authorEmailAddress);
                        if (!$matchingUser) {
                            throw new ValidationException("No corresponding user found for: $authorEmailAddress");
                        }
                    }
                    $personRef = new PersonRef((object) array(
                        'type' => ($grooveMessage['note'] ? 'user' : 'customer'),
                        'email' => $authorEmailAddress,
                        'id' => $id
                    ));
                    $thread->setCreatedBy($personRef);

                    // To field
                    if (isset($grooveMessage['links']['recipient'])) {
                        list($recipientEmailAddress, $addressType) = self::extractEmailAddressFromGrooveLink($grooveMessage['links']['recipient']['href'], 'recipient');
                        if ($recipientEmailAddress) {
                            $thread->setToList(array($recipientEmailAddress));
                        }
                    }

                    $thread->setAttachments(self::retrieveAttachmentsForGrooveMessage($consoleCommand, $servicesMapping, $grooveMessage));

                    $helpscoutThreads []= $thread;
                }
                $pageNumber++;
            } while ($pageNumber < $grooveMessages['meta']['pagination']['total_pages']);
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
            return array($matches[1], 'customer');
        } elseif (preg_match('@^https://api.groovehq.com/v1/agents/(.*)@i',
                $grooveLink, $matches) === 1
        ) {
            return array($matches[1], 'agent');
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
        if (!isset($grooveMessage['links']['attachments'])) {
            return null;
        }

        $messagesService = $servicesMapping['messagesService'];
        $grooveMessageId = null;
        $matches = array();
        $helpscoutAttachments = array();
        if (preg_match('@^https://api.groovehq.com/v1/attachments?message=(.*)@i',
                $grooveMessage['links']['attachments']['href'], $matches) === 1
        ) {
            $grooveMessageId = $matches[1];
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