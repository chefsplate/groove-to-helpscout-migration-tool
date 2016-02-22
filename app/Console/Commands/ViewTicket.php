<?php

namespace App\Console\Commands;

use App\Console\Commands\Processors\TicketProcessor;
use App\Console\Commands\Publishers\TicketPublisher;
use DateTime;
use GuzzleHttp\Command\Exception\CommandClientException;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

class ViewTicket extends SyncCommandBase
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'view-ticket
                            {ticket_number : (Required) Groove ticket number to fetch information on.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Look up details about a particular Groove ticket';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        APIHelper::setConsoleCommand($this);

        date_default_timezone_set('America/Toronto');
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $grooveTicketNumber = $this->argument('ticket_number');
        $this->info("Fetching Groove ticket #$grooveTicketNumber");
        $grooveTicket = null;
        try {
            $ticketsService = $this->getGrooveClient()->tickets();
            $grooveTicket = $this->makeRateLimitedRequest(
                GROOVE,
                function () use ($ticketsService, $grooveTicketNumber) {
                    return $ticketsService->find(['ticket_number' => intval($grooveTicketNumber)])['ticket'];
                });
        } catch (CommandClientException $cce) {
            $this->error($cce->getMessage() . " when fetching Groove ticket number $grooveTicketNumber");
        }

        if (!$grooveTicket) {
            $this->warn("Warning: Requested Groove ticket number $grooveTicketNumber does not exist!");
        } else {
            $this->info(print_r($grooveTicket, TRUE));
        }
    }
}
