<?php

namespace App\Console\Commands;

use GrooveHQ\Client as GrooveClient;
use HelpScout\ApiClient;
use HelpScout\ApiException;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\ProgressBar;

class SyncCommandBase extends Command
{
    // TODO: convert following static fields to an object (so we won't be able to assign array to something else)
    /**
     * @var $requests_processed_this_minute array
     */
    private static $requests_processed_this_minute = array(
        GROOVE => 0,
        HELPSCOUT => 0
    );
    /**
     * @var $start_of_minute_timestamp array
     */
    private static $start_of_minute_timestamp = array(
        GROOVE => 0,
        HELPSCOUT => 0
    );
    /**
     * @var $rate_limits array
     */
    private static $rate_limits = array();

    /**
     * @var array $uploadQueue
     */
    protected $uploadQueue = array();

    private $grooveClient;
    private $helpscoutClient;

    /**
     * @var ProgressBar
     */
    protected $progressBar;

    public function __construct()
    {
        parent::__construct();

        $this->grooveClient = new GrooveClient(config('services.groove.key'));
        $this->helpscoutClient = ApiClient::getInstance();

        try {
            $this->helpscoutClient->setKey(config('services.helpscout.key'));
        } catch (ApiException $e) {
            $this->error("Error creating client");
            $this->error($e->getMessage());
            $this->error(print_r($e->getErrors(), TRUE));
            return;
        }

        $rate_limits[GROOVE] = config('services.groove.ratelimit');
        $rate_limits[HELPSCOUT] = config('services.helpscout.ratelimit');
    }

    public function createProgressBar($total_units)
    {
        $this->progressBar = $this->output->createProgressBar($total_units);
        $this->progressBar->setFormat('%current%/%max% [%bar%] %percent%% %elapsed%/%estimated% | %message%');
        $this->progressBar->setMessage('');
    }

    /**
     * @return ApiClient
     */
    public function getHelpScoutClient() {
        return $this->helpscoutClient;
    }

    /**
     * @return ProgressBar
     */
    public function getProgressBar() {
        return $this->progressBar;
    }

    /**
     * @return GrooveClient
     */
    public function getGrooveClient()
    {
        return $this->grooveClient;
    }

    function addToQueue($jobs_list) {
        $this->uploadQueue = array_merge($this->uploadQueue, $jobs_list);
    }

    /**
     * TODO document this method
     *
     * @param $requestFunction
     * @param callable $processFunction
     * @param callable $publishFunction function will be responsible for popping elements from the upload queue
     * @param $serviceName
     * @return mixed
     */
    public function makeRateLimitedRequest($requestFunction, $processFunction = null, $publishFunction = null, $serviceName) {
        $rateLimit = self::$rate_limits[$serviceName];
        if (SyncCommandBase::$requests_processed_this_minute[$serviceName] >= $rateLimit) {
            $seconds_to_sleep = 60 - (time() - SyncCommandBase::$start_of_minute_timestamp[$serviceName]);
            if ($seconds_to_sleep > 0) {
                $this->progressBar->setMessage("Rate limit reached. Waiting $seconds_to_sleep seconds.");
                $this->progressBar->display();
                sleep($seconds_to_sleep);
                $this->progressBar->setMessage("");
            }
            SyncCommandBase::$start_of_minute_timestamp[$serviceName] = time();
            SyncCommandBase::$requests_processed_this_minute[$serviceName] = 0;
        } elseif (time() - SyncCommandBase::$start_of_minute_timestamp[$serviceName] > 60) {
            SyncCommandBase::$start_of_minute_timestamp[$serviceName] = time();
            SyncCommandBase::$requests_processed_this_minute[$serviceName] = 0;
        }
        $response = $requestFunction();
        SyncCommandBase::$requests_processed_this_minute[$serviceName]++;
        // TODO: refactor processFunction - it should be responsible for adding to the queue
        if ($processFunction != null) {
            /** @var callable $processFunction */
            $this->addToQueue($processFunction($response));
        } else {
            // don't do anything
        }
        if ($publishFunction != null) {
            /** @var callable $publishFunction */
            $publishFunction($this->uploadQueue);
        }
        return $response;
    }
}
