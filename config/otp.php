<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Passcode shape
    |--------------------------------------------------------------------------
    */

    'length' => (int) env('OTP_LENGTH', 6),

    'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 5),

    /** Wrong guesses allowed against one issued code before it is burnt. */
    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),

    /** Seconds a caller must wait before asking for another code. */
    'resend_after_seconds' => (int) env('OTP_RESEND_AFTER', 60),

    /** Codes a single phone may request per hour. */
    'hourly_limit' => (int) env('OTP_HOURLY_LIMIT', 5),

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | Channels are tried in order; the first enabled one wins. Nothing is
    | configured out of the box, so the code is written to the log instead —
    | see App\Services\Notifications\LogChannelGateway.
    |
    */

    'channels' => ['whatsapp', 'sms'],

    'message' => 'رمز الدخول الخاص بك هو :code — صالح لمدة :minutes دقائق. لا تشاركه مع أحد.',

    /**
     * Return the code in the API response so the app can be driven without a
     * live SMS provider. Never true in production.
     */
    'expose_code' => (bool) env('OTP_EXPOSE_CODE', env('APP_ENV', 'production') !== 'production'),

    /*
    |--------------------------------------------------------------------------
    | Fixed demo passcodes
    |--------------------------------------------------------------------------
    |
    | One test account per role: these numbers always accept the code listed
    | here and never consume a provider message, so every role can be tried
    | from the apps without an SMS gateway. Phones are in normalised local
    | form (07XXXXXXXX) — see OtpService::normalisePhone().
    |
    | MUST stay empty in production; `enable_fixed_codes` defaults to off
    | there regardless of what this map contains.
    |
    */

    'enable_fixed_codes' => (bool) env('OTP_FIXED_CODES', env('APP_ENV', 'production') !== 'production'),

    /*
     | Deliberately empty.
     |
     | This map used to carry one passcode per role — 111111 for the system
     | administrator among them. It was gated to non-production, but a login
     | bypass one environment variable away from a live system is not a risk
     | worth keeping for the convenience of demo sign-ins. Development uses
     | `OTP_EXPOSE_CODE=true` instead, which returns the real generated code in
     | the response without creating a standing credential.
     */
    'fixed_codes' => [
        //
    ],
];
