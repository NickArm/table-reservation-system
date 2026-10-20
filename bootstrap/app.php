<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureAccountCanWrite;
use App\Http\Middleware\EnsureBookingsAreOpen;
use App\Http\Middleware\IdentifyTenantFromPath;
use App\Http\Middleware\IdentifyTenantFromUser;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    /*
     * Listeners are declared, not discovered.
     *
     * Discovery is on by default, and scanning app/Listeners would register
     * every handler a second time alongside the explicit map in
     * AppServiceProvider — every voucher emailed twice, every alert sent
     * twice. The map stays the single place where "what happens after a
     * booking is confirmed" can be read rather than inferred from a directory
     * listing (SPEC §31.1).
     */
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant.path' => IdentifyTenantFromPath::class,
            'tenant.user' => IdentifyTenantFromUser::class,
            'bookings.open' => EnsureBookingsAreOpen::class,

            // Read-only once a subscription has run out (SPEC §4.4). Reading
            // keeps working; only writes are refused.
            'account.can-write' => EnsureAccountCanWrite::class,
        ]);

        /*
         * Both resolvers bind their tenant before route model binding runs.
         *
         * Middleware from the `web` group run before a route's own, so
         * SubstituteBindings would otherwise look up /dashboard/setup/{slug},
         * or /reservation/{slug}/book/{hold}, with no tenant in context — and
         * TenantScope rightly refuses rather than searching every venue's
         * rows.
         */
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: IdentifyTenantFromUser::class,
        );

        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: IdentifyTenantFromPath::class,
        );

        // Gateways post signed JSON from their own servers: no session, no
        // token. The signature check in the controller is what protects it.
        $middleware->validateCsrfTokens(except: ['webhooks/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * One error shape for the whole API: { error: { code, message } },
         * never a stack trace (SPEC §15.2.1).
         *
         * Laravel's own JSON errors are shaped differently for a validation
         * failure than for a missing record, and the published contract says
         * one shape. A customer's error handling should not need a branch per
         * status, and a field name we rename later must not change the shape
         * their code reads.
         */
        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            [$status, $code] = match (true) {
                $e instanceof ValidationException => [422, 'invalid_request'],
                $e instanceof ModelNotFoundException => [404, 'not_found'],
                $e instanceof HttpExceptionInterface => [
                    $e->getStatusCode(),
                    $e->getStatusCode() === 404 ? 'not_found' : 'request_failed',
                ],
                default => [500, 'server_error'],
            };

            $message = match (true) {
                $e instanceof ValidationException => (string) collect($e->errors())->flatten()->first(),
                $status === 500 => 'Something went wrong on our side.',
                default => $e->getMessage() !== '' ? $e->getMessage() : 'That request could not be completed.',
            };

            return new JsonResponse(
                ['error' => ['code' => $code, 'message' => $message]],
                $status,
            );
        });
    })->create();
