<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
        |----------------------------------------------------------------------
        | Venue images, on object storage (SPEC §30.1)
        |----------------------------------------------------------------------
        |
        | Logos and hero photographs, which are loaded on **every** visit a
        | tourist makes to a booking page. Egress is therefore the dominant
        | cost of this disk, and Cloudflare R2 charges nothing for it — which
        | is the whole reason the spec names R2 rather than S3.
        |
        | Separate from the stock `s3` disk above, which Laravel ships and
        | something else may one day want for something else. `MEDIA_DISK`
        | picks between this and `public`; `public` stays the default, because
        | a developer machine should not need a bucket to show a logo.
        |
        | Three settings here are each a trap somebody has already fallen
        | into with R2:
        |
        */
        'media' => [
            'driver' => 's3',
            'key' => env('MEDIA_ACCESS_KEY_ID'),
            'secret' => env('MEDIA_SECRET_ACCESS_KEY'),

            /*
             * R2 has one region and calls it `auto`. Giving it anything else
             * is accepted and then quietly ignored, which is worse than an
             * error because the first sign of trouble is a signature that
             * does not match.
             */
            'region' => env('MEDIA_REGION', 'auto'),
            'bucket' => env('MEDIA_BUCKET'),

            /*
             * Where a browser fetches the file from, which on R2 is **not**
             * the API endpoint below: that one needs credentials. It is the
             * bucket's public address or, better, a custom domain — and
             * without it `Storage::url()` builds a URL that answers 401 to
             * every guest.
             */
            'url' => env('MEDIA_URL'),

            'endpoint' => env('MEDIA_ENDPOINT'),
            'use_path_style_endpoint' => env('MEDIA_PATH_STYLE', false),

            /*
             * No `visibility` key, on purpose. Laravel turns `public` into an
             * `ACL: public-read` header, and R2 does not implement per-object
             * ACLs — the upload fails outright. On R2 a bucket is made
             * readable once, in the dashboard, and `MEDIA_URL` above is how
             * anybody reaches it.
             */

            /*
             * Throws rather than returning false. This is the one that
             * matters: with `throw` off, a failed upload returns a quiet
             * `false`, the application stores the path it meant to write,
             * and the booking page shows a broken image with nothing in the
             * log to explain it. An exception reaches the controller, which
             * tells the owner and writes the reason down.
             */
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
