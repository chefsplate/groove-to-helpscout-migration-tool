<?php

namespace App\Console\Commands;

use App\Console\Commands\Processors\TicketProcessor;
use App\Console\Commands\Publishers\TicketPublisher;

class SyncTickets extends SyncCommandBase
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync-tickets {--startPage=1 : The starting page }';

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

        $pageNumber = $this->option('startPage');

        $ticketsService = $this->getGrooveClient()->tickets();

        // Initial validation
        $this->performInitialValidation();

        $grooveTicketsQueryResponse = $this->makeRateLimitedRequest(
            GROOVE,
            function () use ($ticketsService) {
                return $ticketsService->list(['page' => 1, 'per_page' => 10])['meta'];
            });
        $totalTickets = $grooveTicketsQueryResponse['pagination']['total_count'];
        $totalPages = $grooveTicketsQueryResponse['pagination']['total_pages'];

        if ($pageNumber > $totalPages) {
            $this->warn("Warning: Requested page number $pageNumber is greater than total number of pages ($totalPages).");
        }

        $numberTickets = 0;

        while ($pageNumber <= $totalPages) {
            $this->info("\nStarting page " . $pageNumber . " of $totalPages ($totalTickets total tickets)");
            $grooveTicketsResponse = $this->makeRateLimitedRequest(
                GROOVE,
                function () use ($ticketsService, $pageNumber) {
                    return $ticketsService->list(['page' => $pageNumber, 'per_page' => 10])['tickets'];
                },
                TicketProcessor::getProcessor($this),
                TicketPublisher::getPublisher($this)
            );
            $numberTickets += count($grooveTicketsResponse);
            $pageNumber++;
        }

        $this->info("\nCompleted migrating $numberTickets tickets.");
    }

    private function performInitialValidation()
    {
        $mailboxesService = $this->getGrooveClient()->mailboxes();
        $agentsService = $this->getGrooveClient()->agents();

        // Validation check: Ensure each mailbox in Groove maps to a HelpScout mailbox
        $this->info("Validation check: ensuring each mailbox in Groove maps to a HelpScout mailbox");

        $grooveMailboxes = $this->makeRateLimitedRequest(GROOVE, function () use ($mailboxesService) {
            return $mailboxesService->mailboxes()['mailboxes'];
        });

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
        $grooveAgents = $this->makeRateLimitedRequest(GROOVE, function () use ($agentsService) {
            return $agentsService->list()['agents'];
        });

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
