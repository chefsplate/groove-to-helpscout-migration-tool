<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2016-02-11
 * Time: 11:19 AM
 */

namespace App\Console\Commands;


use Exception;
use HelpScout\ApiException;
use HelpScout\Collection;
use HelpScout\model\Mailbox;
use HelpScout\model\User;

class APIHelper
{
    private static $helpscoutMailboxes = null;
    private static $helpscoutUsers = null;

    /**
     * @var SyncCommandBase
     */
    private static $consoleCommand = null;

    public static function setConsoleCommand($consoleCommand) {
        self::$consoleCommand = $consoleCommand;
    }

    /**
     * @param $consoleCommand SyncCommandBase
     * @param bool $forceReload
     * @return array|null
     */
    public static function retrieveHelpscoutMailboxes($forceReload = false)
    {
        if (!self::$consoleCommand) {
            throw new Exception("Console command not set prior to invoking APIHelper methods! Please call APIHelper::setConsoleCommand from the invoking SyncCommandBase.");
        }

        if (self::$helpscoutMailboxes && !$forceReload) {
            return self::$helpscoutMailboxes;
        }

        $pageNumber = 1;
        $cumulativeHelpscoutMailboxes = array();
        try {
            do {
                $consoleCommand = self::$consoleCommand;
                /* @var $helpscoutMailboxesResponse Collection */
                $helpscoutMailboxesResponse = self::$consoleCommand->makeRateLimitedRequest(function () use ($consoleCommand, $pageNumber) {
                    return $consoleCommand->getHelpScoutClient()->getMailboxes(['page' => $pageNumber]);
                }, null, HELPSCOUT);
                $cumulativeHelpscoutMailboxes = array_merge($cumulativeHelpscoutMailboxes, $helpscoutMailboxesResponse->getItems());
                $pageNumber++;
            } while ($helpscoutMailboxesResponse->hasNextPage());
        } catch (ApiException $e) {
            echo $e->getMessage();
            print_r($e->getErrors());
        }

        self::$helpscoutMailboxes = $cumulativeHelpscoutMailboxes;
        return $cumulativeHelpscoutMailboxes;
    }

    /**
     * @param $consoleCommand SyncCommandBase
     * @param bool $forceReload
     * @return array|null
     */
    public static function retrieveHelpscoutUsers($forceReload = false)
    {
        if (!self::$consoleCommand) {
            throw new Exception("Console command not set prior to invoking APIHelper methods!");
        }

        if (self::$helpscoutUsers && !$forceReload) {
            return self::$helpscoutUsers;
        }

        $pageNumber = 1;
        $cumulativeHelpscoutUsers = array();
        try {
            do {
                $consoleCommand = self::$consoleCommand;

                /* @var $helpscoutUsersResponse Collection */
                $helpscoutUsersResponse = self::$consoleCommand->makeRateLimitedRequest(function () use ($consoleCommand, $pageNumber) {
                    return $consoleCommand->getHelpScoutClient()->getUsers(['page' => $pageNumber]);
                }, null, HELPSCOUT);
                $cumulativeHelpscoutUsers = array_merge($cumulativeHelpscoutUsers, $helpscoutUsersResponse->getItems());
                $pageNumber++;
            } while ($helpscoutUsersResponse->hasNextPage());
        } catch (ApiException $e) {
            echo $e->getMessage();
            print_r($e->getErrors());
        }

        self::$helpscoutUsers = $cumulativeHelpscoutUsers;
        return $cumulativeHelpscoutUsers;
    }

    /**
     * @param $mailboxes array
     * @param $mailboxEmail string
     * @return Mailbox
     */
    public static function findMatchingMailboxByEmail($mailboxEmail)
    {
        $mailboxes = self::retrieveHelpscoutMailboxes();

        /* @var $mailbox Mailbox */
        foreach ($mailboxes as $mailbox) {
            if (strcasecmp($mailbox->getEmail(), $mailboxEmail) === 0) {
                return $mailbox;
            }
        }
        return null;
    }

    /**
     * @param $mailboxes array
     * @param $mailboxName string
     * @return Mailbox
     */
    public static function findMatchingMailboxByName($mailboxName)
    {
        $mailboxes = self::retrieveHelpscoutMailboxes();

        /* @var $mailbox Mailbox */
        foreach ($mailboxes as $mailbox) {
            if (strcasecmp($mailbox->getName(), $mailboxName) === 0) {
                return $mailbox;
            }
        }
        return null;
    }

    /**
     * @param $users array
     * @param $emailAddress string
     * @return User
     */
    public static function findMatchingUserWithEmail($emailAddress)
    {
        $users = self::retrieveHelpscoutUsers();

        /* @var $user User */
        foreach ($users as $user) {
            if (strcasecmp($user->getEmail(), $emailAddress) === 0) {
                return $user;
            }
        }
        return null;
    }

    /**
     * @param $fullName string
     * @return array
     */
    public static function extractFirstAndLastNameFromFullName($fullName)
    {
        $spacePos = strpos($fullName, ' ');
        $firstName = null;
        $lastName = null;
        if ($spacePos !== false) {
            $firstName = substr($fullName, 0, $spacePos);
            $lastName = trim(substr($fullName, $spacePos + 1));
        } else {
            $firstName = $fullName;
        }
        return array($firstName, $lastName);
    }
}