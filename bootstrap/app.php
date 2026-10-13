<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureBookingsAreOpen;
use App\Http\Middleware\IdentifyTenantFromPath;
use App\Http\Middleware\IdentifyTenantFromUser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant.path' => IdentifyTenantFromPath::class,
            'tenant.user' => IdentifyTenantFromUser::class,
            'bookings.open' => EnsureBookingsAreOpen::class,
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
    })->create();
