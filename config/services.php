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

    'repomapper' => [
        'path' => env('REPOMAPPER_PATH'),
    ],

    'github' => [
        'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
        'verify_webhook_signature' => env('VERIFY_WEBHOOK_SIGNATURE', true),
        'bot_username' => env('GITHUB_BOT_USERNAME'),
        'app_id' => env('GITHUB_APP_ID'),
        'app_private_key' => env('GITHUB_APP_PRIVATE_KEY'),
        'app_private_key_path' => env('GITHUB_APP_PRIVATE_KEY_PATH'),
    ],

];
