<?php

namespace App\Console\Commands\Publishers;
use App\Console\Commands\SyncCommandBase;
use Closure;

interface PublisherInterface
{
    /**
     * @param $consoleCommand SyncCommandBase the originating console command
     * @return Closure function that takes a list of HelpScout model objects and uploads them
     */
    public static function getPublisher($consoleCommand);
}