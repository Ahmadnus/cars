<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reading an applicant's details off a photo of their ID
    |--------------------------------------------------------------------------
    |
    | Staff copy a name, a national number and a birth date off an ID photo by
    | hand several times a day, and each one is a chance to mistype a digit that
    | nobody notices until the licence paperwork is refused. A vision model reads
    | the card instead and the form is filled from what it read — as a draft a
    | human confirms before saving, never as a fact.
    |
    | Two yeses, the same as the messaging channels: `enabled` here and a key
    | below. With either missing the reader is inert — it records that it was
    | asked and returns nothing, so every screen keeps working and simply does
    | not offer to fill the form.
    |
    | Worth stating plainly, because it is an identity document: with this on,
    | the photo is sent to Anthropic's API to be read. Nothing else about the
    | applicant goes with it, and what comes back is stored on the request row
    | like any other field staff can see.
    |
    */

    'enabled' => env('ID_READER_ENABLED', true),

    'api_key' => env('ANTHROPIC_API_KEY'),

    'model' => env('ID_READER_MODEL', 'claude-opus-5-5'),

    /*
    | Reading a card is close to transcription, so the cheapest setting is
    | normally enough; raise it if cards in poor condition come back empty.
    */
    'effort' => env('ID_READER_EFFORT', 'low'),

    /*
    | Longer than the outbound-message timeouts: this uploads a photo and waits
    | for it to be read, and the receptionist is watching a spinner meanwhile.
    */
    'timeout' => (int) env('ID_READER_TIMEOUT', 60),

    /*
    | How many readings the center is willing to pay for in a day, counting only
    | the ones asked for by people with no account.
    |
    | The per-IP throttle on that route stops one phone from looping; this stops a
    | thousand phones, which is the difference between a rate limit and a bill.
    | Staff readings are not counted — the office must not be locked out of its
    | own queue by public traffic. Set 0 for no cap.
    */
    'daily_limit' => (int) env('ID_READER_DAILY_LIMIT', 200),

    /*
    | What the center issues licences against. The reader keeps a national
    | number only when it has exactly this many digits — a half-read number is
    | worse than an empty field, because it looks like it was checked.
    */
    'national_id_digits' => (int) env('ID_READER_NATIONAL_ID_DIGITS', 10),

];
