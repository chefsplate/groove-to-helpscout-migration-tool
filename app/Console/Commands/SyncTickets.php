<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

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
        $tickets_service = $this->grooveClient->tickets();
        $mailboxes_service = $this->grooveClient->mailboxes();
        $groups_service = $this->grooveClient->groups();
    }
}
