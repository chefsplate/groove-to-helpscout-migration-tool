<?php

namespace App\Console\Commands;

use GrooveHQ\Client as GrooveClient;
use HelpScout\ApiClient;
use HelpScout\ApiException;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\ProgressBar;

class SyncCommandBase extends Command
{
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

        self::$rate_limits[GROOVE] = config('services.groove.ratelimit');
        self::$rate_limits[HELPSCOUT] = config('services.helpscout.ratelimit');
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

    // COMMAND OVERRIDES
    // Clear the progress bar prior to invoking any of the command console's output methods
    // Re-display the progress bar after rendering the output line

    private function isProgressBarActive() {
        return $this->getProgressBar()
            && $this->getProgressBar()->getMaxSteps() !== $this->getProgressBar()->getProgress();
    }

    public function info($string, $verbosity = null) {
        if ($this->isProgressBarActive()) { $this->getProgressBar()->clear(); }
        parent::info($string, $verbosity);
        if ($this->isProgressBarActive()) { $this->getProgressBar()->display(); }
    }

    public function line($string, $style = null, $verbosity = null) {
        if ($this->isProgressBarActive()) { $this->getProgressBar()->clear(); }
        parent::line($string, $style, $verbosity);
        if ($this->isProgressBarActive()) { $this->getProgressBar()->display(); }
    }

    public function comment($string, $verbosity = null) {
        if ($this->isProgressBarActive()) { $this->getProgressBar()->clear(); }
        parent::comment($string, $verbosity);
        if ($this->isProgressBarActive()) { $this->getProgressBar()->display(); }
    }

    public function question($string, $verbosity = null) {
        if ($this->isProgressBarActive()) { $this->getProgressBar()->clear(); }
        parent::question($string, $verbosity);
        if ($this->isProgressBarActive()) { $this->getProgressBar()->display(); }
    }

    public function error($string, $verbosity = null) {
        if ($this->isProgressBarActive()) { $this->getProgressBar()->clear(); }
        parent::error($string, $verbosity);
        if ($this->isProgressBarActive()) { $this->getProgressBar()->display(); }
    }

    public function warn($string, $verbosity = null) {
        if ($this->isProgressBarActive()) { $this->getProgressBar()->clear(); }
        parent::warn($string, $verbosity);
        if ($this->isProgressBarActive()) { $this->getProgressBar()->display(); }
    }

    /**
     * TODO change interface to method passing in configuration object (which is validated)
     *
     * Perform a rate-limited API call. The flow is:
     * 1. requestFunction()
     * 2. processFunction() based on requestFunction result
     * 3. publishFunction() based on processFunction result
     *
     * Only requestFunction() and serviceName are required fields.
     *
     * @param $serviceName
     * @param callable $requestFunction should return a list for processing
     * @param callable $processFunction must return a list of models for publishing
     * @param callable $publishFunction method to upload models; responsible for handling publication failures
     * @return mixed
     */
    public function makeRateLimitedRequest($serviceName, $requestFunction, $processFunction = null, $publishFunction = null) {
        $rateLimit = self::$rate_limits[$serviceName];
        if (SyncCommandBase::$requests_processed_this_minute[$serviceName] >= $rateLimit) {
            $seconds_to_sleep = 60 - (time() - SyncCommandBase::$start_of_minute_timestamp[$serviceName]);
            if ($seconds_to_sleep > 0) {
                $this->progressBar->setMessage("Rate limit reached for '$serviceName'. Waiting $seconds_to_sleep seconds.");
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
        if ($processFunction != null) {
            /** @var callable $processFunction */
            $processedModels = $processFunction($response);

            if ($publishFunction != null) {
                /** @var callable $publishFunction */
                $publishFunction($processedModels);
            }
        } else {
            // don't do anything
        }
        return $response;
    }
}
