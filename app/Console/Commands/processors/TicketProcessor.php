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
    public static function getProcessor($consoleCommand)
    {
        /**
         * @param $ticketsList
         * @return array
         */
        return function ($ticketsList) use ($consoleCommand) {
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
                            $helpscoutPersonRef = new PersonRef((object) array('email' => $customerEmail, 'type' => 'customer'));
                            $conversation->setCustomer($helpscoutPersonRef);
                            $conversation->setCreatedBy($helpscoutPersonRef);
                        } else {
                            $grooveCustomer = $consoleCommand->makeRateLimitedRequest(GROOVE,
                                function () use ($consoleCommand, $customerEmail) {
                                    return $consoleCommand->getGrooveClient()->customers()->find(['customer_email' => $customerEmail])['customer'];
                                },
                                null,
                                null);
                            $helpscoutPersonRef = new PersonRef((object) array('email' => $grooveCustomer['email'], 'type' => 'customer'));
                            $conversation->setCustomer($helpscoutPersonRef);
                            $conversation->setCreatedBy($helpscoutPersonRef);
                        }

                    } else {
                        throw new ApiException("No customer defined for ticket: " . $grooveTicket['number']);
                    }

                    // FIXME why can't we programmatically set this??
                    // CreatedAt
//                    $conversation->setCreatedAt(new DateTime($grooveTicket['created_at']));

                    $conversation->setThreads(self::retrieveThreadsForGrooveTicket($consoleCommand, $grooveTicket));

                    $status = APIHelper::getHelpScoutStatusForGrooveState($grooveTicket['state']);
                    if ($status) {
                        $conversation->setStatus($status);
                    } else {
                        $consoleCommand->error("Unknown state provided: " . $grooveTicket['state']);
                    }

                    $processedTickets [] = $conversation;
                    $consoleCommand->getProgressBar()->advance();
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
     * @param $grooveTicket array
     * @return array
     * @throws ValidationException
     * @internal param array $helpscoutUsers
     */
    private static function retrieveThreadsForGrooveTicket($consoleCommand, $grooveTicket)
    {
        $pageNumber = 1;
        $helpscoutThreads = array();
        try {
            do {
                /* @var $grooveMessages array */
                $grooveMessages = $consoleCommand->makeRateLimitedRequest(GROOVE,
                    function () use ($consoleCommand, $pageNumber, $grooveTicket) {
                        return $consoleCommand->getGrooveClient()->messages()->list(['page' => $pageNumber, 'per_page' => 50, 'ticket_number' => $grooveTicket['number']]);
                    },
                    null,
                    null);

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
                    // FIXME why can't we programmatically set this??
//                    $thread->setCreatedAt(new DateTime($grooveMessage['created_at']));

                    // There is no particular status for a single message in Groove
                    // Assume the status is the same as the ticket's
                    $status = APIHelper::getHelpScoutStatusForGrooveState($grooveTicket['state']);
                    if ($status) {
                        $thread->setStatus($status);
                    } else {
                        $consoleCommand->error("Unknown state provided: " . $grooveTicket['state']);
                    }

                    // CreatedBy is a PersonRef - type must be 'user' for messages or notes
                    // Type must be 'customer' for customer threads
                    // Chat or phone types can be either 'user' or 'customer'
                    // 'user' types require an ID field
                    // 'customer' types require either an ID or email
                    list($authorEmailAddress, $addressType) = self::extractEmailAddressFromGrooveLink($grooveMessage['links']['author']['href'], 'author');
                    $id = null;
                    $personRef = new PersonRef();
                    if (strcasecmp($addressType, 'customer') === 0) {
                        /* @var $response Collection */
                        $helpscoutCustomer = $consoleCommand->makeRateLimitedRequest(HELPSCOUT,
                            function () use ($consoleCommand, $authorEmailAddress) {
                                return $consoleCommand->getHelpScoutClient()->searchCustomersByEmail($authorEmailAddress);
                            },
                            null,
                            null);
                        if ($helpscoutCustomer->getCount() > 0) {
                            /* @var $firstItem \HelpScout\model\Customer */
                            $firstItem = $helpscoutCustomer->getItems()[0];
                            $id = $firstItem->getId();
                        } else {
                            // the customer could be blank - we need to fetch extra details from Groove
                            // perhaps the sync-customers was not run?
                            $consoleCommand->warn('Could not find HelpScout customer for ' . $authorEmailAddress . '. Was sync-customers command run?');
                            $grooveCustomer = $consoleCommand->makeRateLimitedRequest(
                                GROOVE,
                                function () use ($consoleCommand, $authorEmailAddress) {
                                    return $consoleCommand->getGrooveClient()->customers()->searchCustomersByEmail($authorEmailAddress);
                                },
                                null,
                                null);
                            list($firstName, $lastName) = APIHelper::extractFirstAndLastNameFromFullName($grooveCustomer['name']);
                            $personRef->setFirstName($firstName);
                            $personRef->setLastName($lastName);
                        }
                    } else {
                        $matchingUser = APIHelper::findMatchingUserWithEmail($authorEmailAddress);
                        if (!$matchingUser) {
                            throw new ValidationException("No corresponding user found for: $authorEmailAddress");
                        }
                    }
                    $personRef->setType($grooveMessage['note'] ? 'user' : 'customer');
                    $personRef->setEmail($authorEmailAddress);
                    $personRef->setId($id);
                    $thread->setCreatedBy($personRef);

                    // To field
                    if (isset($grooveMessage['links']['recipient'])) {
                        list($recipientEmailAddress, $addressType) = self::extractEmailAddressFromGrooveLink($grooveMessage['links']['recipient']['href'], 'recipient');
                        if ($recipientEmailAddress) {
                            $thread->setToList(array($recipientEmailAddress));
                        }
                    }

                    $thread->setAttachments(self::retrieveAttachmentsForGrooveMessage($consoleCommand, $grooveMessage));

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
     * @param $grooveMessage array
     * @return array
     * @internal param array $grooveTicket
     */
    private static function retrieveAttachmentsForGrooveMessage($consoleCommand, $grooveMessage)
    {
        if (!isset($grooveMessage['links']['attachments'])) {
            return null;
        }

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

        $attachments = $consoleCommand->makeRateLimitedRequest(GROOVE,
            function () use ($consoleCommand, $grooveMessageId) {
                return $consoleCommand->getGrooveClient()->messages()->attachments(['message' => $grooveMessageId])['attachments'];
            },
            null,
            null);

        foreach($attachments as $grooveAttachment) {
            // Attachments: attachments must be sent to the API before they can
            // be used when creating a thread. Use the hash value returned when
            // creating the attachment to associate it with a ticket.
            $consoleCommand->getProgressBar()->setMessage('Attachment ' . $grooveAttachment['filename'] . ' found. Uploading to HelpScout...');
            $helpscoutAttachment = new Attachment();
            $helpscoutAttachment->setFileName($grooveAttachment['filename']);

            $buffer = file_get_contents($grooveAttachment['url']);
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($buffer);

            $helpscoutAttachment->setMimeType($mimeType);
            $helpscoutAttachment->setData(base64_encode($buffer));

            $consoleCommand->makeRateLimitedRequest(function () use ($consoleCommand, $helpscoutAttachment) {
                $consoleCommand->getHelpScoutClient()->createAttachment($helpscoutAttachment);
            }, null, GROOVE);

            // hash should be programmatically be set now
            $helpscoutAttachment->setData(null);
            $consoleCommand->getProgressBar()->setMessage(str_pad(' ', 60));

            $helpscoutAttachments []= $helpscoutAttachment;
        }

        return $helpscoutAttachments;
    }
}