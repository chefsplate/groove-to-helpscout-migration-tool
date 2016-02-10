<?php

namespace App\Console\Commands;

use App\Console\Commands\Processors\CustomerProcessor;
use HelpScout\ApiException;

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

        $customersService = $this->grooveClient->customers();

        $grooveCustomersCountResponse = $this->makeRateLimitedRequest(
            function () use ($customersService) {
                return $customersService->list(['page' => 1, 'per_page' => 1])['meta'];
            },
            null,
            GROOVE);
        $totalCustomers = $grooveCustomersCountResponse['pagination']['total_count'];

        $this->createProgressBar($totalCustomers);

        $pageNumber = 1;
        $numberCustomers = 0;

        do {
            $grooveCustomersListResponse = $this->makeRateLimitedRequest(
                function () use ($customersService, $pageNumber) {
                    return $customersService->list(['page' => $pageNumber, 'per_page' => 50])['customers'];
                },
                CustomerProcessor::getProcessor($this),
                GROOVE);
            $this->progressBar->advance(count($grooveCustomersListResponse));
            $numberCustomers += count($grooveCustomersListResponse);
            $pageNumber++;
        } while (count($grooveCustomersListResponse) > 0 && $pageNumber <= 2);

        $this->progressBar->finish();

        $this->info("\nCompleted fetching $numberCustomers customers.");

        // Publish/create customers
        // ------------------------

        $errorMapping = array();

        $this->createProgressBar(count($this->uploadQueue));

        foreach ($this->uploadQueue as $model) {
            try {
                $classname = explode('\\', get_class($model));
                if (strcasecmp(end($classname), "Customer") === 0) {
                    $client = $this->helpscoutClient;
                    $helpscoutCreateCustomerResponse = $this->makeRateLimitedRequest(function () use ($client, $model) {
                        $client->createCustomer($model);
                    }, null, HELPSCOUT);
                }
            } catch (ApiException $e) {
                foreach ($e->getErrors() as $error) {
                    $errorMapping[$error['message']] [] = $error;
                    $this->progressBar->setMessage('Error: [' . $error['property']. '] ' . $error['message'] . ' (' . $error['value'] . ')' . str_pad(' ', 20));
                }
            }
            $this->progressBar->advance();
        }
        $this->progressBar->finish();

        // TODO: output to a CSV instead
        $this->error(print_r($errorMapping, TRUE));

    }

}
