<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2016-02-11
 * Time: 11:19 AM
 */

namespace App\Console\Commands;


use App\Console\Commands\Processors\Exceptions\ValidationException;
use Exception;
use HelpScout\ApiException;
use HelpScout\Collection;
use HelpScout\model\Mailbox;
use HelpScout\model\User;
use Maatwebsite\Excel\Classes\LaravelExcelWorksheet;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Writers\LaravelExcelWriter;

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
        $consoleCommand = self::$consoleCommand;
        try {
            do {
                /* @var $helpscoutMailboxesResponse Collection */
                $helpscoutMailboxesResponse = self::$consoleCommand->makeRateLimitedRequest(HELPSCOUT, function () use ($consoleCommand, $pageNumber) {
                    return $consoleCommand->getHelpScoutClient()->getMailboxes(['page' => $pageNumber]);
                });
                $cumulativeHelpscoutMailboxes = array_merge($cumulativeHelpscoutMailboxes, $helpscoutMailboxesResponse->getItems());
                $pageNumber++;
            } while ($helpscoutMailboxesResponse->hasNextPage());
        } catch (ApiException $e) {
            $consoleCommand->error("Failed to retrieve HelpScout mailboxes. Message was: " . static::formatApiExceptionArray($e));
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
        $consoleCommand = self::$consoleCommand;
        try {
            do {
                /* @var $helpscoutUsersResponse Collection */
                $helpscoutUsersResponse = self::$consoleCommand->makeRateLimitedRequest(HELPSCOUT,
                    function () use ($consoleCommand, $pageNumber) {
                        return $consoleCommand->getHelpScoutClient()->getUsers(['page' => $pageNumber]);
                    });
                $cumulativeHelpscoutUsers = array_merge($cumulativeHelpscoutUsers, $helpscoutUsersResponse->getItems());
                $pageNumber++;
            } while ($helpscoutUsersResponse->hasNextPage());
        } catch (ApiException $e) {
            $consoleCommand->error("Failed to retrieve HelpScout users. Message was: " . static::formatApiExceptionArray($e));
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
     * @param $consoleCommand SyncCommandBase
     * @return array
     */
    public static function extractFirstAndLastNameFromFullName($fullName, $consoleCommand)
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
        // long names will throw an "org.hibernate.exception.DataException : Data truncation:
        // Data too long for column 'cus_full_name' at row 1" exception on HelpScout's end
        // we should accommodate for it on our end first
        if (strlen($firstName) > 40) {
            $truncated = substr($firstName, 0, 40);
            $consoleCommand->warn("First name \"$firstName\" exceeds maximum 40 allowable characters. Truncating to \"$truncated\"");
            $firstName = $truncated;
        }
        if (strlen($lastName) > 40) {
            $truncated = substr($lastName, 0, 40);
            $consoleCommand->warn("Last name \"$lastName\" exceeds maximum 40 allowable characters. Truncating to \"$truncated\"");
            $lastName = $truncated;
        }

        return array($firstName, $lastName);
    }

    /**
     * @param $state
     * @return string
     */
    public static function getHelpScoutStatusForGrooveState($state)
    {
        switch ($state) {
            case 'unread':
            case 'opened':
                return 'active';
                break;
            case 'pending':
                return 'pending';
                break;
            case 'closed':
                return 'closed';
                break;
            case 'spam':
                return 'spam';
                break;
            default:
                return null;
                break;
        }
    }

    /**
     * Return a single string to be displayed to the console
     * @param ApiException $apiException
     */
    public static function formatApiExceptionArray(ApiException $apiException) {
        $output = $apiException->getMessage();
        if ($apiException->getErrors() && count($apiException->getErrors()) > 0) {
            $errors = $apiException->getErrors();
            foreach($errors as $error) {
                $output .= "\n[" . $error['property'] . "] " . $error['message'] . " (value = " . print_r($error['value'], TRUE) . ")";
            }
        }
        return $output;
    }

    /**
     * Create and save a CSV file with the given filename and contents array.
     *
     * @param $filename string name of the file; by default, exported to app/storage/exports; this can be changed in excel.php
     * @param $contentsArray array an array of rows, followed by an array of column data
     */
    public static function exportArrayToCSV($filename, $contentsArray) {
        Excel::create(
            $filename, function($excel) use ($contentsArray) {
            /* @var $excel LaravelExcelWriter */
            $excel->sheet('Sheet1', function($sheet) use ($contentsArray) {
                /* @var $sheet LaravelExcelWorksheet */
                $sheet->fromArray($contentsArray);
            });
        })->store('csv');
    }

    public static function convertErrorMappingArrayToCSVArray($errorMapping)
    {
        $results = array();
        foreach($errorMapping as $errorType => $issues) {
            foreach($issues as $issue) {
                $results []= array($errorType, $issue);
            }
        }
        return $results;
    }
}