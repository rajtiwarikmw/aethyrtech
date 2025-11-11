<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Amazon Session Cookies
    |--------------------------------------------------------------------------
    |
    | These cookies are used to authenticate with Amazon when scraping reviews.
    | To get these cookies:
    | 1. Login to https://www.amazon.in in your browser
    | 2. Press F12 → Application → Cookies → https://www.amazon.in
    | 3. Copy the values for: session-id, session-id-time, ubid-acbin, at-acbin
    |
    | Note: These cookies expire periodically. Update them when scraping stops working.
    |
    */

    'cookies' => [
        [
            'name' => 'session-id',
            'value' => env('AMAZON_SESSION_ID', '257-5913375-3792854'),
            'domain' => '.amazon.in',
        ],
        [
            'name' => 'session-id-time',
            'value' => env('AMAZON_SESSION_ID_TIME', '2082787201l'),
            'domain' => '.amazon.in',
        ],
        [
            'name' => 'ubid-acbin',
            'value' => env('AMAZON_UBID_ACBIN', '261-2138584-1577751'),
            'domain' => '.amazon.in',
        ],
        [
            'name' => 'at-acbin',
            'value' => env('AMAZON_AT_ACBIN', 'Atza|gQBqkQ0eAwEBAvZJ9fbWZvzzQ0_2viGAp2W1hr6I5vIU2QRpLwC9h-f0yy4lw0aGTbc4m1zYeHGBPQbGXCW5tX41WI-UePy-lCUlg97QmD9_1jUbAonSwLJIyiCHCE2Vq7lECYWtT-I3dr9h84I3ZKMGkXj9W89ZzStXAWKCCyTvD19b9GKKFr4G7tN_o-Jl9WXOtKjy96nbL7DhFkw4VQYvs__4fCPbrP8_ScYNQOZ3TMyWQCqdLM_JmHfSv1U0uEDa-9Btia0ZW4dfjA83U4-nWiYKsxqoeS1giLQ4-JziXWHR2dYPv-qgTzNJoofXcW4FvE8mHUDgtoUiACFPEMZJ3YTj6bS7BpAv-hG_inuZ8AWZPGxkR0csBNf2aJL4EVTLx6A0Vqfs7MnY65XU1lXZf1LwNWkk'),
            'domain' => '.amazon.in',
        ],
        [
            'name' => 'i18n-prefs',
            'value' => 'INR',
            'domain' => '.amazon.in',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cookie Expiration Warning
    |--------------------------------------------------------------------------
    |
    | Number of days before showing a warning that cookies might be expired.
    |
    */

    'expiration_warning_days' => 30,
];
