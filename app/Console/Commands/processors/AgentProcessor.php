<?php

namespace App\Console\Commands\Processors;
use HelpScout\ApiException;

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2016-02-04
 * Time: 1:29 PM
 */
class AgentProcessor implements ProcessorInterface
{
    /**
     * @param null $consoleCommand
     * @param null $servicesMapping
     * @return Closure
     */
    public static function getProcessor($consoleCommand = null, $servicesMapping = null)
    {
        return function ($agents_list) {
            $processed_agents = array();
            foreach ($agents_list as $groove_agent) {

                // Groove: email, first_name, last_name, href
                // HelpScout Person: id, firstName, lastName, email, phone, type (user, customer, team)
                // Note: HelpScout cannot programmatically create users/team members

                try {
                    var_dump($groove_agent);
                    // TODO: ensure mapping exists in user-mapping
                } catch (ApiException $e) {
                    echo $e->getMessage();
                    print_r($e->getErrors());
                }
            }
            return $processed_agents;
        };
    }
}