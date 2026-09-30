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

    'mcp' => [
        // Bearer token for the app-level MCP server (/mcp/global); leave empty to disable it
        'global_token' => env('MCP_GLOBAL_TOKEN'),
    ],

    'chrome' => [
        // The PDF printer (docker/chrome), private on the compose network
        'url' => env('CHROME_URL', 'http://chrome:3000'),
        // The app as the printer reaches it: `web`, not APP_URL, which is the
        // address a person's browser uses and may not resolve in a container
        'origin' => env('CHROME_APP_ORIGIN', 'http://web:8080'),
    ],

];
