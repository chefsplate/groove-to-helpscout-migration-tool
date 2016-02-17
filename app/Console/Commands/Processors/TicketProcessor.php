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
use HelpScout\model\SearchConversation;
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
    private static $processor;

    /**
     * @param $consoleCommand SyncCommandBase
     * @return \Closure
     */
    public static function getProcessor($consoleCommand)
    {
        if (null === static::$processor) {
            static::$processor = self::generateProcessor($consoleCommand);
        }

        return static::$processor;
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

        do {
            /* @var $grooveMessages array */
            $grooveMessages = $consoleCommand->makeRateLimitedRequest(GROOVE,
                function () use ($consoleCommand, $pageNumber, $grooveTicket) {
                    return $consoleCommand->getGrooveClient()->messages()->list(['page' => $pageNumber, 'per_page' => 50, 'ticket_number' => $grooveTicket['number']]);
                });

            foreach ($grooveMessages['messages'] as $grooveMessage) {
                $authorEmailAddress = null;
                try {
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
                    $datetime = new DateTime($grooveMessage['created_at']);
                    $thread->setCreatedAt($datetime->format('c'));

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
                            });
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
                                function () use ($consoleCommand, $grooveMessage) {
                                    // we need to make a raw curl request because the current version of the
                                    // Groove/Guzzle API client does not support disabling urlencoding in URL parameters
                                    // this is apparently a Groove API requirement
                                    $url = $grooveMessage['links']['author']['href'] . '?access_token=' . config('services.groove.key');
                                    $jsonData = json_decode(file_get_contents($url), true);
                                    return $jsonData['customer'];
                                });
                            list($firstName, $lastName) = APIHelper::extractFirstAndLastNameFromFullName($grooveCustomer['name']);
                            $personRef->setFirstName($firstName);
                            $personRef->setLastName($lastName);
                        }
                    } else {
                        $matchingUser = APIHelper::findMatchingUserWithEmail($authorEmailAddress);
                        if (!$matchingUser) {
                            throw new ValidationException("No corresponding user found for: $authorEmailAddress");
                        }
                        $id = $matchingUser->getId();
                        $personRef->setFirstName($matchingUser->getFirstName());
                        $personRef->setLastName($matchingUser->getLastName());

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

                    list($attachments, $failedAttachmentNotes) = self::retrieveAttachmentsForGrooveMessage($consoleCommand, $grooveMessage, $status);
                    $thread->setAttachments($attachments);

                    $helpscoutThreads [] = $thread;

                    if (count($failedAttachmentNotes) > 0) {
                        $helpscoutThreads = array_merge($helpscoutThreads, $failedAttachmentNotes);
                    }
                } catch (ApiException $e) {
                    $consoleCommand->error("Failed to create HelpScout thread for Groove message (" . $grooveMessage['href'] . " created by $authorEmailAddress at " . $grooveMessage['created_at'] . "). Message was: \n" . APIHelper::formatApiExceptionArray($e));
                }
            }
            $pageNumber++;
        } while ($pageNumber < $grooveMessages['meta']['pagination']['total_pages']);

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
     * @param $ticketStatus
     * @return array
     * @throws ApiException
     * @throws \Exception
     * @internal param array $grooveTicket
     */
    private static function retrieveAttachmentsForGrooveMessage($consoleCommand, $grooveMessage, $ticketStatus)
    {
        if (!isset($grooveMessage['links']['attachments'])) {
            return null;
        }

        $grooveMessageId = null;
        $matches = array();
        $helpscoutAttachments = array();
        $failedAttachments = array();
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
            });

        foreach($attachments as $grooveAttachment) {
            // Attachments: attachments must be sent to the API before they can
            // be used when creating a thread. Use the hash value returned when
            // creating the attachment to associate it with a ticket.
            $fileName = $grooveAttachment['filename'];
            $fileSize = $grooveAttachment['size'];
            $consoleCommand->info("Attachment $fileName found ($fileSize bytes). Uploading to HelpScout...");
            $helpscoutAttachment = new Attachment();
            $helpscoutAttachment->setFileName($fileName);

            $buffer = file_get_contents($grooveAttachment['url']);
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($buffer);
            $helpscoutAttachment->setMimeType($mimeType);
            $helpscoutAttachment->setData($buffer);

            if (intval($grooveAttachment['size']) > 10485760) {
                $consoleCommand->warn("Warning: Maximum file size supported by HelpScout is 10 MB (10485760 bytes). File size for $fileName is $fileSize bytes.");
            }
            try {
                $consoleCommand->makeRateLimitedRequest(GROOVE, function () use ($consoleCommand, $helpscoutAttachment) {
                    $consoleCommand->getHelpScoutClient()->createAttachment($helpscoutAttachment);
                });

                // hash should be programmatically be set now
                $helpscoutAttachment->setData(null);

                $helpscoutAttachments []= $helpscoutAttachment;
            } catch (\Exception $e) {
                $consoleCommand->error("Failed to create HelpScout attachment for $fileName: " . $e->getMessage());

                // For whatever reason the upload failed, let's create a private note indicating containing a
                // link where the attachment can be viewed
                $note = new Note();
                $note->setType('note');
                $note->setStatus($ticketStatus);

                $createdBy = new PersonRef();
                $createdBy->setId(config('services.helpscout.default_user_id'));
                $createdBy->setType('user');
                $note->setCreatedBy($createdBy);

                $datetime = new DateTime($grooveMessage['created_at']);
                $note->setCreatedAt($datetime->format('c'));

                $url = $grooveAttachment['url'];
                $note->setBody("Attachment \"$fileName\" failed to upload. Please view original here: <a href=\"$url\">$url</a>");
                $failedAttachments []= $note;
            }
        }

        return array($helpscoutAttachments, $failedAttachments);
    }

    /**
     * @param $consoleCommand SyncCommandBase
     * @return \Closure
     */
    private static function generateProcessor($consoleCommand)
    {
        return function ($ticketsList) use ($consoleCommand) {
            $processedTickets = array();
            $checkForDuplicates = $consoleCommand->option('checkDuplicates');

            foreach ($ticketsList as $grooveTicket) {
                $customerEmail = null;
                try {
                    if ($checkForDuplicates) {
                        /* @var $searchResults Collection */
                        $dateString = $grooveTicket['created_at'];
                        $searchResults = $consoleCommand->makeRateLimitedRequest(HELPSCOUT, function () use ($consoleCommand, $dateString) {
                            return $consoleCommand->getHelpScoutClient()->conversationSearch("(modifiedAt:[$dateString TO $dateString])");
                        });
                        if ($searchResults->getCount() > 1) {
                            $helpscoutConversationNumber = null;
                            /* @var $searchConversation SearchConversation */
                            foreach ($searchResults->getItems() as $searchConversation) {
                                if (strcasecmp($searchConversation->getSubject(), $grooveTicket['title']) === 0) {
                                    $helpscoutConversationNumber = $searchConversation->getNumber();
                                    break;
                                }
                            }
                            if ($helpscoutConversationNumber) {
                                $consoleCommand->warn("Warning: Duplicate ticket \"" . $grooveTicket['title'] . "\" on $dateString already uploaded to HelpScout (conversation #$helpscoutConversationNumber). Skipping.");
                                continue;
                            }
                        }
                    }

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
                            $grooveTicket['links']['customer']['href'], $matches) === 1
                    ) {
                        $customerEmail = $matches[1];
                        if (filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                            $helpscoutPersonRef = new PersonRef((object)array('email' => $customerEmail, 'type' => 'customer'));
                            $conversation->setCustomer($helpscoutPersonRef);
                            $conversation->setCreatedBy($helpscoutPersonRef);
                        } else {
                            $grooveCustomer = $consoleCommand->makeRateLimitedRequest(GROOVE,
                                function () use ($consoleCommand, $customerEmail) {
                                    return $consoleCommand->getGrooveClient()->customers()->find(['customer_email' => $customerEmail])['customer'];
                                });
                            $helpscoutPersonRef = new PersonRef((object)array('email' => $grooveCustomer['email'], 'type' => 'customer'));
                            list($firstName, $lastName) = APIHelper::extractFirstAndLastNameFromFullName($grooveCustomer['name']);
                            $helpscoutPersonRef->setFirstName($firstName);
                            $helpscoutPersonRef->setLastName($lastName);
                            $conversation->setCustomer($helpscoutPersonRef);
                            $conversation->setCreatedBy($helpscoutPersonRef);
                        }

                    } else {
                        throw new ApiException("No customer defined for ticket: " . $grooveTicket['number']);
                    }

                    // CreatedAt
                    $datetime = new DateTime($grooveTicket['created_at']);
                    $conversation->setCreatedAt($datetime->format('c'));

                    $conversation->setThreads(self::retrieveThreadsForGrooveTicket($consoleCommand, $grooveTicket));

                    $status = APIHelper::getHelpScoutStatusForGrooveState($grooveTicket['state']);
                    if ($status) {
                        $conversation->setStatus($status);
                    } else {
                        $consoleCommand->error("Unknown state provided: " . $grooveTicket['state']);
                    }

                    $processedTickets [] = $conversation;
                } catch (ApiException $e) {
                    $consoleCommand->error("Failed to create HelpScout conversation for Groove ticket (#" . $grooveTicket['number'] . " created by $customerEmail at " . $grooveTicket['created_at'] . "). Message was: \n" . APIHelper::formatApiExceptionArray($e));
                } catch (\CurlException $ce) {
                    $errorMessage = "CurlException encountered for ticket " . $grooveTicket['number'] . " \"" . $grooveTicket['summary'] . "\"";
                    $consoleCommand->error($errorMessage . ": " . $ce->getMessage());
                } catch (\ErrorException $errex) {
                    $errorMessage = "Error encountered for ticket " . $grooveTicket['number'] . " \"" . $grooveTicket['summary'] . "\"";
                    $consoleCommand->error($errorMessage . ": " . $errex->getMessage());
                } catch (\Exception $ex) {
                    $errorMessage = "Exception encountered for ticket " . $grooveTicket['number'] . " \"" . $grooveTicket['summary'] . "\"";
                    $consoleCommand->error($errorMessage . ": " . $ex->getMessage());
                }
            }
            return $processedTickets;
        };
    }
}