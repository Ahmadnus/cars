<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Generated email domain
    |--------------------------------------------------------------------------
    |
    | Trainees, trainers and employees sign in with their phone number, but a
    | user row requires an email. When the office does not supply one, an
    | address is derived on this domain. It is never sent to — so it must be a
    | domain the center controls or an obviously internal one, never a real
    | provider where the address might belong to someone.
    |
    */

    'generated_email_domain' => env('ACCOUNTS_EMAIL_DOMAIN', 'accounts.invalid'),

];
