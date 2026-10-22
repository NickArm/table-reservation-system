<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | The platform's own payment credentials
    |--------------------------------------------------------------------------
    |
    | These are ours, for charging venues their subscription (SPEC §4.3). They
    | are not to be confused with the keys in `payment_gateway_settings`, which
    | belong to each venue and charge that venue's own guests into that
    | venue's own account.
    |
    | The distinction is the whole commercial position of the product: their
    | money never passes through us. Mixing the two up in either direction —
    | a guest's dinner charged to our account, or our subscription charged to
    | theirs — would be the most serious bug this application could have. So
    | they live in different tables, under different names, and ours are in
    | config rather than the database because there is only ever one of us.
    |
    */

    'provider' => env('BILLING_PROVIDER', 'stripe'),

    /*
    |--------------------------------------------------------------------------
    | Test or live
    |--------------------------------------------------------------------------
    |
    | Separate from APP_ENV on purpose. Running the staging site against live
    | billing keys would charge real cards; running production against test
    | keys would take no money at all while looking like it had.
    |
    */

    'mode' => env('BILLING_MODE', 'test'),

    'stripe' => [
        'secret' => env('BILLING_STRIPE_SECRET'),
        'webhook_secret' => env('BILLING_STRIPE_WEBHOOK_SECRET'),
    ],

    'paypal' => [
        'client_id' => env('BILLING_PAYPAL_CLIENT_ID'),
        'client_secret' => env('BILLING_PAYPAL_CLIENT_SECRET'),

        // From the webhook you create in the PayPal dashboard. Without it a
        // webhook cannot be verified, and an unverified webhook is a stranger
        // telling us somebody paid.
        'webhook_id' => env('BILLING_PAYPAL_WEBHOOK_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Where a venue lands again
    |--------------------------------------------------------------------------
    |
    | Both providers send the browser back here after checkout. Neither return
    | is trusted: the application asks the provider what happened rather than
    | believing a URL anybody could type (SPEC §24.2).
    |
    */

    'return_route' => 'dashboard.billing.returned',
    'cancel_route' => 'dashboard.billing',

];
