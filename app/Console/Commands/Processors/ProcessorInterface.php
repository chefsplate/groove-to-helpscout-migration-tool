<?php

namespace App\Console\Commands\Processors;
use App\Console\Commands\SyncCommandBase;
use Closure;

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2016-02-04
 * Time: 1:32 PM
 */
interface ProcessorInterface
{
    /**
     * @param $consoleCommand SyncCommandBase the originating console command
     * @return Closure function that takes a list of Groove model objects and returns HelpScout model objects to be uploaded
     */
    public static function getProcessor($consoleCommand);
}