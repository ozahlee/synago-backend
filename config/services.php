<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    /*
    | Bulk SMS. SMS_DRIVER=log (default) only writes to the log; set it to "africastalking" with credentials to send.
    */
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'country_code' => env('SMS_COUNTRY_CODE', '256'),
        'africastalking' => [
            'username' => env('AT_USERNAME', 'sandbox'),
            'api_key' => env('AT_API_KEY'),
            'sender_id' => env('AT_SENDER_ID'),
            'sandbox' => env('AT_USERNAME', 'sandbox') === 'sandbox',
        ],
    ],

];
