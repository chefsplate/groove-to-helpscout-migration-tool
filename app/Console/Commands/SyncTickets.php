<?php

namespace App\Console\Commands;

use App\Console\Commands\Processors\TicketProcessor;
use App\Console\Commands\Publishers\TicketPublisher;
use DateTime;
use GuzzleHttp\Command\Exception\CommandClientException;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

class SyncTickets extends SyncCommandBase
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync-tickets
                            {--startPage=1 : The starting page } {--stopPage=9999 : The last page to fetch } {--bypassValidation : Skip initial validation checks. Please be careful!} {--customerEmails=null : A comma-seperated list of customer emails, any email listed will be treated as a customer. This addresses a discrepancy where a Groove customer can leave a private note } {--checkDuplicates=true : Check whether each ticket has already been uploaded to HelpScout. If so, then don\'t upload a new one. } {tickets? : (Optional) Comma-separated list (no spaces) of specific Groove ticket numbers for syncing. Useful for resuming failed uploads.}';

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
        $style = new OutputFormatterStyle('white', 'black', array('bold'));
        $this->output->getFormatter()->setStyle('header', $style);

        $this->info("[START] Starting sync of Groove tickets and messages at " . date('c'));

        if (!$this->option('bypassValidation')) {
            // Initial validation
            $this->performInitialValidation();
        }

        // Acquire and process
        // -------------------
        $ticketsToSync = $this->argument('tickets');
        if ($ticketsToSync) {
            $this->migrateSpecificGrooveTickets($ticketsToSync);
        } else {
            $this->migrateAllTickets();
        }

        $this->info("[FINISH] Sync of Groove tickets and messages completed at " . date('c'));
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

    /**
     * @param $ticketsToSync array
     */
    private function migrateSpecificGrooveTickets($ticketsToSync)
    {
        $ticketsService = $this->getGrooveClient()->tickets();

        $grooveTicketNumbers = explode(',', $ticketsToSync);
        $grooveTickets = array();

        // Acquire all tickets first
        foreach($grooveTicketNumbers as $grooveTicketNumber) {
            $this->info("Fetching Groove ticket #$grooveTicketNumber");
            $grooveTicket = null;
            try {
                $grooveTicket = $this->makeRateLimitedRequest(
                    GROOVE,
                    function () use ($ticketsService, $grooveTicketNumber) {
                        return $ticketsService->find(['ticket_number' => intval($grooveTicketNumber)])['ticket'];
                    });
            } catch (CommandClientException $cce) {
                $this->error($cce->getMessage() . " when fetching Groove ticket number $grooveTicketNumber");
            }

            if (!$grooveTicket) {
                $this->warn("Warning: Requested Groove ticket number $grooveTicketNumber does not exist!");
            } else {
                $grooveTickets [] = $grooveTicket;
            }
        }

        // Process responses
        $processedModels = call_user_func(TicketProcessor::getProcessor($this), $grooveTickets);

        // Publish (upload) HelpScout models
        $numberTickets = count($processedModels);
        if ($numberTickets > 0) {
            call_user_func(TicketPublisher::getPublisher($this), $processedModels);
        }

        $this->info("\nCompleted migrating $numberTickets tickets.");
    }

    private function migrateAllTickets()
    {
        $pageNumber = $this->option('startPage');
        $stopPage = $this->option('stopPage');
        $startPage = $pageNumber;
        $startTime = new DateTime();

        $ticketsService = $this->getGrooveClient()->tickets();

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
        if ($stopPage > $totalPages) {
            $stopPage = $totalPages;
        }
        if ($stopPage < $pageNumber) {
            $this->warn("Warning: Requested stop page $stopPage is less than starting page requested $pageNumber. ");
        }

        $numberTickets = 0;

        while ($pageNumber <= $stopPage) {
            $this->line("\n\n=== Starting page " . $pageNumber . " of $stopPage ($totalTickets total tickets, $totalPages total pages) ===", 'header');
            $this->displayETA($startTime, $startPage, $pageNumber, $stopPage);
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

}
