<?php

declare(strict_types=1);

use App\Observability\SentryScrubber;

return [

    /*
    |--------------------------------------------------------------------------
    | Error tracking (SPEC §30.2)
    |--------------------------------------------------------------------------
    |
    | Written by hand rather than left as the package's published file,
    | because two of its defaults work against us and the reasons belong next
    | to the settings.
    |
    | Empty DSN means nothing is sent and nothing breaks, which is the state
    | every developer machine and the test suite run in.
    |
    */

    'dsn' => env('SENTRY_LARAVEL_DSN'),

    /*
    |--------------------------------------------------------------------------
    | Which deploy this is
    |--------------------------------------------------------------------------
    |
    | The single most useful field on a report: it answers "did my change
    | cause this" without having to remember when the change went out. Set it
    | from the deploy script; shelling out to git on every request would be a
    | process spawn per request, and on shared hosting `.git` may not even be
    | there.
    |
    */

    'release' => env('SENTRY_RELEASE'),

    /*
    | Separate from APP_ENV so that staging and production can report into one
    | project and still be told apart (§30.3). Null falls back to APP_ENV,
    | which is the right answer when nobody has set it.
    */
    'environment' => env('SENTRY_ENVIRONMENT'),

    'sample_rate' => 1.0,

    /*
    |--------------------------------------------------------------------------
    | Performance monitoring: off
    |--------------------------------------------------------------------------
    |
    | Traces eat a free quota in days, and the question they answer — which
    | query is slow — is not the question worth paying for yet. The question
    | worth paying for is "did a booking fail", which is an error, not a
    | trace. Raise it when there is a performance problem to look at.
    |
    */

    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.0),
    'profiles_sample_rate' => 0.0,

    'enable_logs' => false,
    'enable_metrics' => false,

    /*
    |--------------------------------------------------------------------------
    | No personal information, and not negotiable
    |--------------------------------------------------------------------------
    |
    | Left on, Sentry attaches the request body, headers, cookies and IP by
    | itself. In this application the request body of the page that matters
    | most is a booking form — a tourist's name, email address and telephone
    | number, sent to a processor our own privacy page does not name (§12.2,
    | §24.7).
    |
    | Hard-coded rather than read from the environment on purpose: a thing
    | this consequential should not be one mistyped line in a `.env` away
    | from being on.
    |
    */

    'send_default_pii' => false,

    /*
    |--------------------------------------------------------------------------
    | The scrubber
    |--------------------------------------------------------------------------
    |
    | Runs on every event as the last step before it leaves. `send_default_pii`
    | above stops Sentry collecting personal data by itself; this stops *our
    | own* data going out — a stack trace's local variables are where a
    | venue's live gateway secret sits when the payment flow throws.
    |
    | A static method and not a closure, because a closure here cannot be
    | serialised and `php artisan config:cache` — which every deploy runs —
    | fails on it. There is a test that this stays a non-closure.
    |
    */

    'before_send' => [SentryScrubber::class, 'handle'],

    /*
    |--------------------------------------------------------------------------
    | Noise that would spend the quota
    |--------------------------------------------------------------------------
    |
    | None of these mean anything is wrong with the application, and all of
    | them happen constantly: a crawler asking for a page that is not there,
    | somebody typing a date in the wrong format, a hold that expired exactly
    | as designed, an owner mistyping their own password.
    |
    | Note what is *not* ignored: `TenantNotResolved`. That one means a query
    | was about to cross between venues, and it is the single most important
    | exception in the system.
    |
    */

    'ignore_exceptions' => [
        Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
        Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class,
        Illuminate\Validation\ValidationException::class,
        Illuminate\Auth\AuthenticationException::class,
        Illuminate\Auth\Access\AuthorizationException::class,
        Illuminate\Session\TokenMismatchException::class,
        Illuminate\Database\Eloquent\ModelNotFoundException::class,
        Illuminate\Http\Exceptions\ThrottleRequestsException::class,
    ],

    /*
    | The health-check noise of a cron-driven application: a scheduler tick
    | every minute would otherwise be the busiest transaction in the project.
    */
    'ignore_transactions' => [
        'GET /up',
    ],

    /*
    |--------------------------------------------------------------------------
    | Breadcrumbs
    |--------------------------------------------------------------------------
    |
    | SQL *statements* without their bindings: the statement says which query
    | broke, the bindings are a guest's email address.
    |
    */

    'breadcrumbs' => [
        'logs' => true,
        'cache' => false,
        'livewire' => false,
        'sql_queries' => true,
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => true,
        'notifications' => true,
    ],

    'tracing' => [
        'queue_job_transactions' => false,
        'sql_bindings' => false,
        'views' => false,
        'default_integrations' => true,
    ],

];
