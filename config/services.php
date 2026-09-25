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

    /*
    |--------------------------------------------------------------------------
    | Outbound messaging
    |--------------------------------------------------------------------------
    |
    | SMS and WhatsApp providers for notifications and login passcodes. The
    | admin still has to switch the channel on under الإعدادات ← الإشعارات;
    | credentials alone do not start sending. Leave these blank and the
    | messages are written to the log instead.
    |
    */

    'messaging' => [
        // Used to promote locally written numbers (07…) to international form.
        'country_code' => env('MESSAGING_COUNTRY_CODE', '962'),
    ],

    'twilio' => [
        'sid' => env('TWILIO_ACCOUNT_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_FROM'),
    ],

    'whatsapp' => [
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'token' => env('WHATSAPP_ACCESS_TOKEN'),
        // Leave null to send plain text (only valid inside the 24h window).
        'template' => env('WHATSAPP_TEMPLATE'),
        'template_language' => env('WHATSAPP_TEMPLATE_LANG', 'ar'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging
    |--------------------------------------------------------------------------
    |
    | `credentials` is a filesystem path to the service account JSON, never the
    | key itself: a private key does not belong in an environment variable that
    | gets echoed into logs and crash reports. Keep the file outside the web
    | root and readable only by the application user.
    |
    */

    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'credentials' => env('FCM_CREDENTIALS_PATH'),
    ],

];
