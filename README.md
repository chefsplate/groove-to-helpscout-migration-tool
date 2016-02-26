# groove-to-helpscout-migration-tool

ETL project to migrate from GrooveHQ to HelpScout via APIs.

This ETL tool uses the Acquire -> Process -> Publish sequence of phases as suggested by http://www.seabourneinc.com/rethinking-etl-for-the-api-age/

## Requirements

- PHP 5.4+ (including mbstring, pdo extensions)
- `allow_url_fopen` must be allowed (for attachment downloads)
- [Composer](https://getcomposer.org/download/)

Yum packages: `httpd24 php56 php56-mbstring php56-pdo`

### Dependencies

We leverage the following libraries via Composer:
- [Laravel](https://laravel.com/docs/5.1/installation) (for console commands)
- [helpscout/api](https://github.com/helpscout/helpscout-api-php) (HelpScout API Client)
- [jadb/php-groovehq](https://github.com/jadb/php-groovehq) (Groove API Client)
- [Laravel Excel](http://www.maatwebsite.nl/laravel-excel/docs) (for exporting results to CSV)

## Usage

Clone project and run `composer install` in the root folder of this project.

Update config/services.php to use your API keys and your default HelpScout mailbox.

Ensure mailbox names within HelpScout correspond to the same mailbox names as Groove. A check will be made before syncing tickets.

### Within HelpScout

Create all of your agent (team, user & mailbox) accounts in HelpScout first. Please ensure historical users are created as well (at least temporarily for this import. They can be safely deleted afterwards). 
Our tool will need to map the Groove agent email addresses with HelpScout user emails and Groove mailboxes to HelpScout mailboxes.

### CLI Usage

#### Customers

In the root of the Laravel project, run: 
```
php artisan sync-customers
``` 
Customers come first, as the process of creating conversations may create a new customer.

You can also resume a previously stopped migration by passing in a `startPage` parameter:
```
php artisan sync-customers --startPage=10
```

#### Tickets

Once the syncing of customers succeeds, run: 
```
php artisan sync-tickets
```
to migrate Groove tickets, messages, images, attachments and tags.

The `sync-tickets` command also accepts the `startPage` and `stopPage` optional parameters. 
For example, to fetch only the tickets on pages 10 to 20, execute the following command:
```
php artisan sync-customers --startPage=10 --stopPage=20
```

If, for any reason, a particular ticket fails to migrate (e.g. connectivity issues), you can 
redo `sync-tickets` with just that particular ticket number:
  
```
php artisan sync-tickets [<comma-separated list of Groove ticket numbers>] 
```

e.g. `php artisan sync-tickets 1000,2000,3000` will sync over just tickets #1000, 2000, and 3000.

By default, `sync-tickets` will ensure no duplicate tickets are created (keep in mind there is a slight delay before HelpScout picks up newly-created tickets).
You can bypass the duplication check (e.g. on the initial import) by specifying:

```
php artisan sync-tickets --checkDuplicates=false 
```

Finally, if there are any issues with the initial validation, or you want to ignore these messages, 
you can do so at your own risk by invoking:

```
php artisan sync-tickets --bypassValidation
```

#### Viewing tickets

If for whatever reason a ticket fails to sync, you can display Groove information about it with the `view-ticket` command:
```
php artisan view-ticket <Groove ticket number> 
```

For all commands, you can also specify `--help` to read additional details.  

## Notes

This tool is compatible with V1 of both Groove and HelpScout APIs.

As with all content management systems: Garbage in, garbage out.

If your customer's full name is their phone number, do not expect the first and last name in HelpScout to make any
sense. If the email is invalid, you will likely need to manually create these users yourself.

### What is migrated
- Customers
- Tickets
- Messages
- Attachments and images
- Tags

### What is not migrated
These will need to be manually created:
- Agents/users and groups
- Mailboxes and folders
- Reports
- Webhooks

### Mapping limitations and known issues

Please be aware of the following when importing:
- Groove stores full names of customers instead of first and last name
- Groove does not maintain customer addresses
- Groove only supports a single email address field (we will do our best to parse multiple email addresses)
- Groove mainly supports Twitter and LinkedIn as social media platforms
- Groove doesn't have chat accounts out-of-the-box
- HelpScout API does not support creation of team members (agents); team members and mailboxes will have to be manually created
- HelpScout API doesn't appear to have any way of indicating who closed a particular ticket
- HelpScout API doesn't appear to have any concept of priorities for conversations/threads
- HelpScout API can only upload attachments up to 7.6 MB in size, although the API documentation indicates 10 MB is supported. The HelpScout team is aware of this and will address this in the future.

For help moving from Zendesk, Desk or UserVoice, check out the [HelpScout knowledge base](http://docs.helpscout.net/category/74-copying-email-to-help-scout).

#### "No corresponding user found" issues

If you are running into an error where no corresponding user can be found, please ensure an agent account has been created in HelpScout that matches the email address in question 
(these accounts need to be temporarily created for the migration; it is safe to delete accounts afterwards - please see the next section below).
 
If the email belongs to a customer, then you may have stumbled upon a discrepancy between the two systems - customers can leave notes in Groove; however, only agents/users can leave notes in HelpScout. 
To work around this, set the `customerEmails` option parameter on the `sync-tickets` command:

```
php artisan sync-tickets --customerEmails=[<comma-separated list of customer emails>] <Groove ticket number> 
```
Any email addresses matching the emails listed in customerEmails will be treated as a customer.

##### Deleting temporary users

It is safe to delete a user that was created for the purposes of the migration. 
As described from HelpScout support, once a user is deleted: 

- All previous replies and notes added to conversations remain in place.
- Reporting is not affected in any way.
- Any workflows created by that User will not be affected.
- Conversations that are assigned to the User will automatically become unassigned.
- Status values will not changed for any conversations that the User has touched.

### Dealing with a large number of attachments

If you are getting FatalErrorExceptions because the memory has been exhausted, you can create a php.ini file with the following contents:
```
memory_limit = 256M
```

You can then run `sync-tickets` using the specified php.ini configuration file:
```
php -c php.ini artisan sync-tickets
```

The memory limit can be increased to 512M if necessary.

## Challenges

- Long-running process (may take hours; batches may fail anytime).
- Queueing several jobs while adhering to API rate limit. 
- Monitoring on the front-end. How do you tell which objects have been successfully migrated and which ones failed?