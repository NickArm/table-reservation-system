<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Trial
    |--------------------------------------------------------------------------
    |
    | Days of full access after signing up, with no card (SPEC §4.2).
    |
    */

    'trial_days' => (int) env('TENANCY_TRIAL_DAYS', 15),

    /*
    |--------------------------------------------------------------------------
    | Grace period
    |--------------------------------------------------------------------------
    |
    | Days after a failed renewal before the account locks (SPEC §4.3).
    |
    */

    'grace_days' => (int) env('TENANCY_GRACE_DAYS', 3),

    /*
    |--------------------------------------------------------------------------
    | Data retention
    |--------------------------------------------------------------------------
    |
    | Months an expired account's data is kept. Twelve, not three, because a
    | seasonal venue cancels in November and comes back in April; shorter and
    | they return to an empty account (SPEC §4.4).
    |
    */

    'retention_months' => (int) env('TENANCY_RETENTION_MONTHS', 12),

    /*
    |--------------------------------------------------------------------------
    | Deletion delay
    |--------------------------------------------------------------------------
    |
    | Days between an owner asking for deletion and the data being purged.
    | Protects against a regretted click (SPEC §29.3).
    |
    */

    'purge_after_days' => (int) env('TENANCY_PURGE_AFTER_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Defaults for a new account
    |--------------------------------------------------------------------------
    |
    | The migrations carry the same values as column defaults, which keeps a
    | row written outside the application sane. They are repeated here because
    | a column default is applied by the database and never appears on the
    | model that `create()` hands back: reading `$tenant->timezone` straight
    | after creating the tenant returns null, and anything derived from it —
    | the venue's own timezone, every opening hour, every slot — inherits the
    | null. So a value the application reads back is passed in explicitly, and
    | the column default stays as the floor rather than the source.
    |
    */

    'default_timezone' => env('TENANCY_DEFAULT_TIMEZONE', 'Europe/Athens'),

    'default_locale' => env('TENANCY_DEFAULT_LOCALE', 'en'),

    'default_currency' => env('TENANCY_DEFAULT_CURRENCY', 'EUR'),

    /*
    |--------------------------------------------------------------------------
    | Reserved slugs
    |--------------------------------------------------------------------------
    |
    | Never handed out as a venue slug, because they collide with real routes
    | or read as official.
    |
    */

    'reserved_slugs' => [
        'admin', 'api', 'app', 'dashboard', 'embed', 'login', 'register',
        'logout', 'password', 'reservation', 'reservations', 'support',
        'help', 'billing', 'account', 'settings', 'terms', 'privacy', 'dpa',
        'status', 'up', 'www', 'mail', 'static', 'assets', 'storage',
    ],

];
