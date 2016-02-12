<?php

namespace App\Console\Commands;

use App\Console\Commands\Processors\CustomerProcessor;
use App\Console\Commands\Publishers\CustomerPublisher;

class SyncCustomers extends SyncCommandBase
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync-customers';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Syncs customers from Groove over to HelpScout';

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
        // Acquire and process
        // -------------------

        $customersService = $this->getGrooveClient()->customers();

        $grooveCustomersCountResponse = $this->makeRateLimitedRequest(
            GROOVE,
            function () use ($customersService) {
                return $customersService->list(['page' => 1, 'per_page' => 1])['meta'];
            });
        $totalCustomers = $grooveCustomersCountResponse['pagination']['total_count'];

        $this->createProgressBar($totalCustomers);

        $pageNumber = 1;
        $numberCustomers = 0;

        do {
            $grooveCustomersListResponse = $this->makeRateLimitedRequest(
                GROOVE,
                function () use ($customersService, $pageNumber) {
                    return $customersService->list(['page' => $pageNumber, 'per_page' => 50])['customers'];
                },
                CustomerProcessor::getProcessor($this),
                CustomerPublisher::getPublisher($this));
            $numberCustomers += count($grooveCustomersListResponse);
            $pageNumber++;
        } while (count($grooveCustomersListResponse) > 0 && $pageNumber <= 2);

        $this->progressBar->finish();


        $this->info("\nCompleted migrating $numberCustomers customers.");
    }

}
