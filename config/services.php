<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Stripe, Mailgun, Mandrill, and others. This file provides a sane
    | default location for this type of information, allowing packages
    | to have a conventional place to find your various credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
    ],

    'mandrill' => [
        'secret' => env('MANDRILL_SECRET'),
    ],

    'ses' => [
        'key'    => env('SES_KEY'),
        'secret' => env('SES_SECRET'),
        'region' => 'us-east-1',
    ],

    'stripe' => [
        'model'  => App\User::class,
        'key'    => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
    ],

    // Groove HQ client API key
    'groove' => [
        'key' => 'REPLACE ME',
        'ratelimit' => 30
    ],

    // HelpScout client API key
    'helpscout' => [
        'key' => 'REPLACE ME',
        'ratelimit' => 200,
        'default_mailbox' => 'david.chang@chefsplate.com',
        'default_user_id' => 0 // REPLACE ME: this user ID will be used to auto-generate notes in the case attachment uploads fail
    ]

];
