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
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        //
        $agents_service = $this->grooveClient->agents();
        $messages_service = $this->grooveClient->messages();
        $mailboxes_service = $this->grooveClient->mailboxes();
        $groups_service = $this->grooveClient->groups();

        // Acquire and process
        // -------------------

        $ticketsService = $this->grooveClient->tickets();

        $response = $this->makeRateLimitedRequest(
            function () use ($ticketsService) {
                return $ticketsService->list(['page' => 1, 'per_page' => 1])['meta'];
            },
            null,
            config('services.groove.ratelimit'));
        $totalTickets = $response['pagination']['total_count'];

        $this->createProgressBar($totalTickets);

        $pageNumber = 1;
        $numberTickets = 0;

        do {
            $response = $this->makeRateLimitedRequest(
                function () use ($ticketsService, $pageNumber) {
                    return $ticketsService->list(['page' => $pageNumber, 'per_page' => 50])['tickets'];
                },
                TicketProcessor::getProcessor($this, array('ticketsService' => $ticketsService)),
                config('services.groove.ratelimit'));
            $this->progressBar->advance(count($response));
            $numberTickets += count($response);
            $pageNumber++;
        } while (count($response) > 0 && $pageNumber <= 2);

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
                    $response = $this->makeRateLimitedRequest(function () use ($client, $model) {
                        $client->createConversation($model);
                    }, null, config('services.helpscout.ratelimit'));
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
