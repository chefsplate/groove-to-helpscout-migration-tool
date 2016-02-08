<?php

namespace App\Console\Commands;

use App\Console\Commands\Processors\CustomerProcessor;
use Illuminate\Console\Command;
use HelpScout\ApiClient;

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
        $gh = new \GrooveHQ\Client(config('services.groove.key'));
        $customers_service = $gh->customers();


        $response = $this->makeRateLimitedRequest(
            function () use ($customers_service) {
                return $customers_service->list(['page' => 1, 'per_page' => 1])['meta'];
            },
            null,
            config('services.groove.ratelimit'));
        $total_customers = $response['pagination']['total_count'];

        $this->progress_bar = $this->output->createProgressBar($total_customers);
        $this->progress_bar->setFormat('%current%/%max% [%bar%] %percent%% %elapsed%/%estimated% | %message%');
        $this->progress_bar->setMessage('');

        $page_number = 1;
        $number_customers = 0;

        do {
            $response = $this->makeRateLimitedRequest(
                function () use ($customers_service, $page_number) {
                    return $customers_service->list(['page' => $page_number, 'per_page' => 50])['customers'];
                },
                CustomerProcessor::getProcessor(),
                config('services.groove.ratelimit'));
            $this->progress_bar->advance(count($response));
            $number_customers += count($response);
            $page_number++;
        } while (count($response) > 0 && $page_number <= 2);

        $this->progress_bar->finish();

        $this->info("\nCompleted fetching $number_customers customers.");


        // Create customers
        $client = null;
        try {
            $client = ApiClient::getInstance();
            $client->setKey(config('services.helpscout.key'));
        } catch (\HelpScout\ApiException $e) {
            $this->error("Error creating client");
            $this->error($e->getMessage());
            $this->error(print_r($e->getErrors(), TRUE));
            return;
        }

        $error_mapping = array();

        $this->progress_bar = $this->output->createProgressBar(count($this->uploadQueue));
        $this->progress_bar->setFormat('%current%/%max% [%bar%] %percent%% %elapsed%/%estimated% %message%');

        foreach ($this->uploadQueue as $model) {
            try {
                $classname = explode('\\', get_class($model));
                if (strcasecmp(end($classname), "Customer") === 0) {
                    $response = $this->makeRateLimitedRequest(function () use ($client, $model) {
                        $client->createCustomer($model);
                    }, null, config('services.helpscout.ratelimit'));
                }
            } catch (\HelpScout\ApiException $e) {
                foreach ($e->getErrors() as $error) {
                    $error_mapping[$error['message']] [] = $error;
                    $this->progress_bar->setMessage('Error: [' . $error['property']. '] ' . $error['message'] . ' (' . $error['value'] . ')' . str_pad(' ', 20));
                }
            }
            $this->progress_bar->advance();
        }
        $this->progress_bar->finish();

        // TODO: output to a CSV instead
        $this->error(print_r($error_mapping, TRUE));

    }
}
