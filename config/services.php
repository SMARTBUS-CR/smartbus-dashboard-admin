<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'smartbus' => [
        'gateway' => [
            'url' => env('API_GATEWAY_URL', 'https://smartbus-api-gateway.test'),
            'timeout' => env('API_GATEWAY_TIMEOUT', 30),
            'log_failures' => env('API_GATEWAY_LOG_FAILURES', true),
        ],
    ],

    'photon' => [
        'url' => env('PHOTON_URL', 'https://photon.komoot.io'),
        'timeout' => 8,
        'cache_ttl' => 3600,
    ],

    'osrm' => [
        'url' => env('OSRM_URL', 'https://router.project-osrm.org'),
        'timeout' => 10,
        'cache_ttl' => 3600,
        'user_agent' => 'SmartBus Dashboard University Project',
    ],
];
