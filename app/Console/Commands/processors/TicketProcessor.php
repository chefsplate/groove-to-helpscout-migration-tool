<?php

namespace App\Console\Commands\Processors;

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2016-02-04
 * Time: 1:29 PM
 *
 */
class TicketProcessor implements ProcessorInterface
{
    /**
     * @return Closure
     */
    public static function getProcessor()
    {
        return function ($customers_list) {
            $processed_customers = array();
            foreach ($customers_list as $groove_customer) {


                makeRateLimitedRequest(null, null, null);

                // Groove: email, name, about, twitter_username, title, company_name, phone_number, location, website_url, linkedin_username
                // HelpScout Customer (subset of Person): firstName, lastName, photoUrl, photoType, gender, age, organization, jobTitle, location, createdAt, modifiedAt
                // HelpScout Person: id, firstName, lastName, email, phone, type (user, customer, team)

                try {
                    $customer = new \HelpScout\model\Customer();

                    // Groove doesn't separate these fields
                    $full_name = $groove_customer['name'];
                    $spacePos = strpos($full_name, ' ');
                    if ($spacePos !== false) {
                        $customer->setFirstName(substr($full_name, 0, $spacePos));
                        $customer->setLastName((trim(substr($full_name, $spacePos + 1))));
                    } else {
                        $customer->setFirstName($full_name);
                    }

                    $customer->setOrganization($groove_customer['company_name']);
                    // Job title must be 60 characters or less
                    $customer->setJobTitle(substr($groove_customer['title'], 0, 60));
                    $customer->setLocation($groove_customer['location']);
                    $customer->setBackground($groove_customer['about']);

                    // Groove doesn't have addresses

                    if ($groove_customer['phone_number'] != null) {
                        $phonenumber = new \HelpScout\model\customer\PhoneEntry();
                        $phonenumber->setValue($groove_customer['phone_number']);
                        $phonenumber->setLocation("home");
                        $customer->setPhones(array($phonenumber));
                    }

                    // Emails: at least one email is required
                    // Groove only supports one email address, which means the email field could contain multiple emails
                    $email_addresses = array();
                    $split_emails = preg_split("/( |;|,)/", $groove_customer['email']);
                    // test to make sure all email addresses are valid
                    if (sizeof($split_emails) == 1) {
                        $email_entry = new \HelpScout\model\customer\EmailEntry();
                        $email_entry->setValue($groove_customer['email']);
                        $email_entry->setLocation("primary");

                        array_push($email_addresses, $email_entry);
                    } else {
                        // Test to make sure every email address is valid
                        $first = true;
                        foreach ($split_emails as $address_to_test) {
                            if (strlen(trim($address_to_test)) === 0) {
                                continue;
                            }
                            if (!filter_var($address_to_test, FILTER_VALIDATE_EMAIL)) {
                                // breaking up the address resulted in invalid emails; use the original address
                                $email_addresses = array();
                                $email_entry = new \HelpScout\model\customer\EmailEntry();
                                $email_entry->setValue($groove_customer['email']);
                                $email_entry->setLocation("primary");

                                array_push($email_addresses, $email_entry);

                                break;
                            } else {
                                $email_entry = new \HelpScout\model\customer\EmailEntry();
                                $email_entry->setValue($address_to_test);

                                if ($first) {
                                    $email_entry->setLocation("primary");
                                    $first = false;
                                } else {
                                    $email_entry->setLocation("other");
                                }

                                array_push($email_addresses, $email_entry);
                            }
                        }
                    }
                    $customer->setEmails($email_addresses);

                    // Social Profiles (Groove supports Twitter and LinkedIn)
                    $social_profiles = array();
                    if ($groove_customer['twitter_username'] != null) {
                        $twitter = new \HelpScout\model\customer\SocialProfileEntry();
                        $twitter->setValue($groove_customer['twitter_username']);
                        $twitter->setType("twitter");
                        $social_profiles [] = $twitter;
                    }

                    if ($groove_customer['linkedin_username'] != null) {
                        $linkedin = new \HelpScout\model\customer\SocialProfileEntry();
                        $linkedin->setValue($groove_customer['linkedin_username']);
                        $linkedin->setType("linkedin");
                        $social_profiles [] = $linkedin;
                    }

                    $customer->setSocialProfiles($social_profiles);

                    // Groove doesn't have chats

                    if ($groove_customer['website_url'] != null) {
                        $website = new \HelpScout\model\customer\WebsiteEntry();
                        $website->setValue($groove_customer['website_url']);

                        $customer->setWebsites(array($website));
                    }

                    $processed_customers [] = $customer;
                } catch (\HelpScout\ApiException $e) {
                    echo $e->getMessage();
                    print_r($e->getErrors());
                }
            }
            return $processed_customers;
        };
    }
}