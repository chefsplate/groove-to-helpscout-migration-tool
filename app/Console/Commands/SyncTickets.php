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
        $mailboxesService = $this->grooveClient->mailboxes();
        $customersService = $this->grooveClient->customers();
        $agentsService = $this->grooveClient->agents();

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
                    return $ticketsService->list(['page' => $pageNumber, 'per_page' => 50])['tickets'];
                },
                TicketProcessor::getProcessor($this, array('ticketsService' => $ticketsService,
                    'messagesService' => $messagesService,
                    'mailboxesService' => $mailboxesService,
                    'customersService' => $customersService,
                    'agentsService' => $agentsService)),
                GROOVE);
            $this->progressBar->advance(count($grooveTicketsResponse));
            $numberTickets += count($grooveTicketsResponse);
            $pageNumber++;
        } while (count($grooveTicketsResponse) > 0 && $pageNumber <= 2);

        $this->progressBar->finish();

        $this->info("\nCompleted fetching $numberTickets tickets.");

        // Publish/create tickets
        // ----------------------

        $errorMapping = array();

        $this->createProgressBar(count($this->uploadQueue));

        foreach ($this->uploadQueue as $model) {
            try {
                $classname = explode('\\', get_class($model));
                if (strcasecmp(end($classname), "Conversation") === 0) {
                    $client = $this->helpscoutClient;
                    $createConversationResponse = $this->makeRateLimitedRequest(function () use ($client, $model) {
                        $client->createConversation($model, true); // imported = true to prevent spam!
                    }, null, HELPSCOUT);
                }
            } catch (ApiException $e) {
                foreach ($e->getErrors() as $error) {
                    $errorMapping[$error['message']] [] = $error;
                    $this->progressBar->setMessage('Error: [' . $error['property'] . '] ' . $error['message'] . ' (' . $error['value'] . ')' . str_pad(' ', 20));
                }
            }
            $this->progressBar->advance();
        }
        $this->progressBar->finish();

        // TODO: output to a CSV instead
        $this->error(print_r($errorMapping, TRUE));

    }
}
