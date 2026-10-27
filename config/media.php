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

    /*
     * `public` on a developer machine, `media` in production — the R2 disk in
     * `config/filesystems.php`. A developer should not need a bucket to show a
     * logo, and production should not be serving a season of photographs off
     * a shared host's disk, where the backups multiply the volume (§30.1).
     *
     * Nothing anywhere stores a URL, only a path, which is what lets this
     * move between the two without rewriting a single row.
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
