<?php

namespace App\Console\Commands\Processors;

use App\Console\Commands\APIHelper;
use App\Console\Commands\Processors\Exceptions\ValidationException;
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

            foreach ($ticketsList as $grooveTicket) {
                try {
                    $conversation = new Conversation();
                    $conversation->setType('email');
                    $conversation->setSubject($grooveTicket['title']);

                    // mailbox
                    $mailboxName = $grooveTicket['mailbox'];
                    $assignedMailbox = APIHelper::findMatchingMailboxByName($mailboxName);
                    if (!$assignedMailbox) {
                        $mailboxRef = APIHelper::findMatchingMailboxByEmail(config('services.helpscout.default_mailbox'))->toRef();
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

                    $conversation->setThreads(self::retrieveThreadsForGrooveTicket($consoleCommand, $servicesMapping, $grooveTicket));

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
                    $consoleCommand->progressBar->advance();
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
     * @return array
     * @throws ValidationException
     * @internal param array $helpscoutUsers
     */
    private static function retrieveThreadsForGrooveTicket($consoleCommand, $servicesMapping, $grooveTicket)
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
                        $matchingUser = APIHelper::findMatchingUserWithEmail($authorEmailAddress);
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
        if (preg_match('@^https://api.groovehq.com/v1/attachments\?message=(.*)@i',
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