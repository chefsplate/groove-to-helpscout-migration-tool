<?php
namespace App\Console\Commands\Models;

use HelpScout\model\Conversation;

/**
 * A HelpScout Conversation with Groove context
 *
 * Created by PhpStorm.
 * User: david
 * Date: 2016-02-19
 * Time: 8:56 AM
 */
class HybridConversation
{
    /* @var $conversation Conversation */
    private $conversation;

    /* @var $grooveTicketNumber int */
    private $grooveTicketNumber;

    /**
     * @return Conversation
     */
    public function getConversation()
    {
        return $this->conversation;
    }

    /**
     * @param $conversation Conversation
     */
    public function setConversation($conversation)
    {
        $this->conversation = $conversation;
    }

    /**
     * @return int
     */
    public function getGrooveTicketNumber()
    {
        return $this->grooveTicketNumber;
    }

    /**
     * @param $grooveTicketNumber int
     */
    public function setGrooveTicketNumber($grooveTicketNumber)
    {
        $this->grooveTicketNumber = $grooveTicketNumber;
    }
}