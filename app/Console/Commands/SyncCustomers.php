<?php

namespace App\Console\Commands;

use App\Console\Commands\Processors\CustomerProcessor;
use App\Console\Commands\Publishers\CustomerPublisher;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

class SyncCustomers extends SyncCommandBase
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync-customers {--startPage=1 : The starting page }';

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
        $style = new OutputFormatterStyle('white', 'black', array('bold'));
        $this->output->getFormatter()->setStyle('header', $style);

        // Acquire and process
        // -------------------

        $pageNumber = $this->option('startPage');

        $customersService = $this->getGrooveClient()->customers();

        $grooveCustomersCountResponse = $this->makeRateLimitedRequest(
            GROOVE,
            function () use ($customersService, $pageNumber) {
                return $customersService->list(['page' => $pageNumber, 'per_page' => 50])['meta'];
            });
        $totalCustomers = $grooveCustomersCountResponse['pagination']['total_count'];
        $totalPages = $grooveCustomersCountResponse['pagination']['total_pages'];

        if ($pageNumber > $totalPages) {
            $this->warn("Warning: Requested page number $pageNumber is greater than total number of pages ($totalPages).");
        }

        $numberCustomers = 0;

        while ($pageNumber <= $totalPages) {
            $this->line("\n\n=== Starting page " . $pageNumber . " of $totalPages ($totalCustomers total customers) ===", 'header');
            $grooveCustomersListResponse = $this->makeRateLimitedRequest(
                GROOVE,
                function () use ($customersService, $pageNumber) {
                    return $customersService->list(['page' => $pageNumber, 'per_page' => 50])['customers'];
                },
                CustomerProcessor::getProcessor($this),
                CustomerPublisher::getPublisher($this));
            $numberCustomers += count($grooveCustomersListResponse);
            $pageNumber++;
        }

        $this->info("\nCompleted migrating $numberCustomers customers.");
    }

}
