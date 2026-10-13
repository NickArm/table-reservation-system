<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Where venue images live
    |--------------------------------------------------------------------------
    |
    | Logos and hero images, on the public disk in development and on object
    | storage in production (SPEC §30.3). Object storage is the reason this is
    | a setting and not a hard-coded path: shared hosting cannot serve a
    | season's worth of photos, and R2 charges nothing for egress.
    |
    */

    'disk' => env('MEDIA_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | Phone cameras produce 6 MB files that nobody needs full size. Uploads are
    | resized down to these widths, which keeps a booking page under a second
    | on a beach with one bar of signal.
    |
    */

    'hero' => [
        'max_width' => 2000,
        'quality' => 72,
    ],

    'logo' => [
        'max_width' => 600,
    ],

    'max_upload_kilobytes' => 8192,

];
