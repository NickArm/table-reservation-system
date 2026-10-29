<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | The demo account on the front page
    |--------------------------------------------------------------------------
    |
    | A venue owner deciding whether this is worth fifteen minutes will not
    | fill in a signup form first. So the front page hands out a real login to
    | a seeded venue with a season of bookings behind it — the fastest honest
    | answer to "what does it actually look like".
    |
    | It is a switch rather than a paragraph in a template, because the day
    | this product has paying customers is the day those credentials must stop
    | being printed on the home page. One env var does it, and the box
    | disappears with its own test to prove it.
    |
    | Never the super admin. That account sees every venue on the platform.
    |
    */

    'enabled' => (bool) env('DEMO_LOGIN_SHOWN', env('APP_ENV') !== 'production'),

    'password' => (string) env('DEMO_PASSWORD', 'password'),

    /*
     * Two roles, because they see different things: an owner gets the money
     * and the settings, a waiter gets the floor. Showing only one of them
     * makes the permissions look like a claim rather than a feature.
     */
    'accounts' => [
        [
            'email' => (string) env('DEMO_OWNER_EMAIL', 'owner@demo.test'),
            'role' => 'Owner',
            'sees' => 'Everything: the money, the settings, the season.',
        ],
        [
            'email' => (string) env('DEMO_STAFF_EMAIL', 'staff@demo.test'),
            'role' => 'Staff',
            'sees' => 'The floor and the bookings. No money, no settings.',
        ],
    ],

];
