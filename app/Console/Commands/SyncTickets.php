<?php

namespace App\Console\Commands;

use App\Console\Commands\Processors\TicketProcessor;
use HelpScout\ApiException;

class SyncTickets extends SyncCommandBase
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync-tickets';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync tickets from Groove to HelpScout';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        APIHelper::setConsoleCommand($this);

        date_default_timezone_set('America/Toronto');
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // Acquire and process
        // -------------------

        // TODO: refactor into base command - get rid of mapping
        $ticketsService = $this->grooveClient->tickets();
        $messagesService = $this->grooveClient->messages();
        $customersService = $this->grooveClient->customers();
        $agentsService = $this->grooveClient->agents();
        $mailboxesService = $this->grooveClient->mailboxes();

        // Initial validation
        $this->performInitialValidation();

        $grooveTicketsQueryResponse = $this->makeRateLimitedRequest(
            function () use ($ticketsService) {
                return $ticketsService->list(['page' => 1, 'per_page' => 1])['meta'];
            },
            null,
            GROOVE);
        $totalTickets = $grooveTicketsQueryResponse['pagination']['total_count'];

        $this->createProgressBar($totalTickets);

        $pageNumber = 1;
        $numberTickets = 0;

        do {
            $grooveTicketsResponse = $this->makeRateLimitedRequest(
                function () use ($ticketsService, $pageNumber) {
                    return $ticketsService->list(['page' => $pageNumber, 'per_page' => 10])['tickets'];
                },
                TicketProcessor::getProcessor($this, array('ticketsService' => $ticketsService,
                    'messagesService' => $messagesService,
                    'mailboxesService' => $mailboxesService,
                    'customersService' => $customersService,
                    'agentsService' => $agentsService)),
                GROOVE);
            $numberTickets += count($grooveTicketsResponse);
            $pageNumber++;
        } while (count($grooveTicketsResponse) > 0 && $pageNumber <= 1);

        $this->progressBar->finish();

        $this->info("\nCompleted fetching $numberTickets tickets.");

        // Publish/create tickets
        // ----------------------

        $errorMapping = array();

        $this->info("Uploading tickets to HelpScout...");

        $this->createProgressBar(count($this->uploadQueue));

        /* @var $model \HelpScout\model\Conversation */
        foreach ($this->uploadQueue as $model) {
            try {
                $classname = explode('\\', get_class($model));
                if (strcasecmp(end($classname), "Conversation") === 0) {
                    $client = $this->helpscoutClient;
                    $createConversationResponse = $this->makeRateLimitedRequest(function () use ($client, $model) {
                        // very important!! set imported = true to prevent spam!
                        $client->createConversation($model, true);
                    }, null, HELPSCOUT);
                }
            } catch (ApiException $e) {
                if ($e->getErrors()) {
                    foreach ($e->getErrors() as $error) {
                        $errorMapping[$error['message']] [] = $error;
                        $this->progressBar->setMessage('Error: [' . $error['property'] . '] ' . $error['message'] . ' (' . $error['value'] . ')' . str_pad(' ', 20));
                    }
                } else {
                    $errorMapping[$e->getMessage()] []= $model->getSubject();
                }
            } catch (\CurlException $ce) {
                $errorMessage = "CurlException encountered for ticket \"" . $model->getSubject() . "\" (created by " . $model->getCreatedBy()->getEmail() . ")";
                $this->error($errorMessage . ": " . $ce->getMessage());
                $errorMapping[$ce->getMessage()] []= $errorMessage;
            } catch (\ErrorException $errex) {
                $errorMessage = "Exception encountered for ticket \"" . $model->getSubject() . "\" (created by " . $model->getCreatedBy()->getEmail() . ")";
                $this->error($errorMessage . ": " . $errex->getMessage());
                $errorMapping[$errex->getMessage()] []= $errorMessage;
            } catch (\Exception $ex) {
                $errorMessage = "Exception encountered for ticket \"" . $model->getSubject() . "\" (created by " . $model->getCreatedBy()->getEmail() . ")";
                $this->error($errorMessage . ": " . $ex->getMessage());
                $errorMapping[$ex->getMessage()] []= $errorMessage;
            }
            $this->progressBar->advance();
        }
        $this->progressBar->finish();
        $this->info("\nCompleted uploading tickets to HelpScout.");

        if (sizeof($errorMapping) > 0) {
            // TODO: output to a CSV instead
            $this->error(print_r($errorMapping, TRUE));
        }



    }

    private function performInitialValidation()
    {
        $mailboxesService = $this->grooveClient->mailboxes();
        $agentsService = $this->grooveClient->agents();

        // Validation check: Ensure each mailbox in Groove maps to a HelpScout mailbox
        $this->info("Validation check: ensuring each mailbox in Groove maps to a HelpScout mailbox");

        $grooveMailboxes = $this->makeRateLimitedRequest(function () use ($mailboxesService) {
            return $mailboxesService->mailboxes()['mailboxes'];
        }, null, GROOVE);

        $hasErrors = false;

        foreach($grooveMailboxes as $grooveMailbox) {
            $grooveMailboxName = $grooveMailbox['name'];
            if (!($helpscoutMailbox = APIHelper::findMatchingMailboxByName($grooveMailboxName))) {
                $this->error('Missing corresponding HelpScout mailbox named: ' . $grooveMailboxName);
                $hasErrors = true;
            } else {
                $this->info("[ OK ] " . $grooveMailboxName . " mapped to " . $helpscoutMailbox->getEmail() . " (" . $helpscoutMailbox->getId() . ")");
            }
        }

        // Validation check: Ensure each agent has a corresponding user in HelpScout
        $this->info("\nValidation check: ensuring each Groove agent maps to a corresponding HelpScout user");
        $grooveAgents = $this->makeRateLimitedRequest(function () use ($agentsService) {
            return $agentsService->list()['agents'];
        }, null, GROOVE);

        foreach($grooveAgents as $grooveAgent) {
            $grooveAgentEmail = $grooveAgent['email'];
            if (!($helpscoutUser = APIHelper::findMatchingUserWithEmail($grooveAgentEmail))) {
                $this->error('Missing corresponding HelpScout user for email: ' . $grooveAgentEmail);
                $hasErrors = true;
            } else {
                $this->info("[ OK ] " . $grooveAgentEmail . " mapped to HelpScout user " . $helpscoutUser->getFullName() . " (" . $helpscoutUser->getId() . ")");
            }
        }

        if ($hasErrors) {
            $this->error("\nValidation failed. Please correct the above errors, otherwise we cannot proceed.");
            exit();
        }
        $this->info("\nValidation passed.");
    }
}
