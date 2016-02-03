# groove-to-helpscout-migration-tool
ETL project to migrate from GrooveHQ to HelpScout via APIs.

This ETL tool uses the Acquire -> Process -> Publish sequence of phases as suggested by http://www.seabourneinc.com/rethinking-etl-for-the-api-age/

## Requirements

- PHP 5.4+
- [Composer](https://getcomposer.org/download/)

### Dependencies

We leverage the following libraries:
- [helpscout/api](https://github.com/helpscout/helpscout-api-php)
- [jadb/php-groovehq](https://github.com/jadb/php-groovehq)

## Usage

Clone project and run `composer install` in the root folder of this project.

In the same folder, run:
`php -S localhost:8001`

Navigate to http://localhost:8001/index.php in your browser.