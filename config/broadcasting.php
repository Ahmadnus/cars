<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default driver
    |--------------------------------------------------------------------------
    |
    | `log` out of the box, so every broadcast is recorded and the whole flow
    | is testable before a provider exists. Set BROADCAST_CONNECTION=pusher
    | once the credentials are in place.
    |
    | Pusher is the driver of choice here because the application runs on shared
    | hosting: there is no Node, no Redis and no process supervisor, so a
    | self-hosted WebSocket server could not be kept alive reliably. A hosted
    | service moves that problem off the box entirely.
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'log'),

    'connections' => [

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER', 'eu'),
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'eu').'.pusher.com',
                'port' => (int) env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                // Chat carries personal messages and voice notes, so transport
                // encryption is required rather than merely preferred.
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                'timeout' => 10,
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
