<?php

declare(strict_types=1);

use App\Api\V1\OpenApi;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\IdentifyTenantFromPath;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API v1
|--------------------------------------------------------------------------
|
| Called by the customer's own server, never by their browser: the token
| belongs server-side, and a call that arrives with an Origin header gets
| flagged in their dashboard as exposed (SPEC §15.2.1).
|
| Versioned from the first day. A breaking change becomes /v2 with /v1 alive
| for at least twelve months, which is the promise that makes it worth
| anyone's while to integrate at all.
|
| Read-only in Phase 1. The booking and the payment always happen on our own
| pages, where the availability engine and the hold live — so a custom form
| asks these endpoints what is free and then sends the visitor to us.
*/

/*
 * The schema, for generating a client rather than typing one.
 *
 * Outside the token middleware on purpose: it describes shapes, holds no data
 * and no secrets, and making a generator authenticate in order to fetch the
 * description of the thing it is about to build a client for is an annoyance
 * with no security benefit.
 */
Route::get('v1/openapi.json', fn (): array => OpenApi::document())->name('api.v1.openapi');

Route::prefix('v1')
    ->middleware(AuthenticateApiToken::class)
    ->name('api.v1.')
    ->group(function (): void {
        Route::get('venue', [AvailabilityController::class, 'venue'])->name('venue');
        Route::get('booking-types', [AvailabilityController::class, 'bookingTypes'])->name('booking-types');
        Route::get('days', [AvailabilityController::class, 'days'])->name('days');
        Route::get('times', [AvailabilityController::class, 'times'])->name('times');
        Route::get('availability', [AvailabilityController::class, 'spots'])->name('availability');
        Route::get('booking-url', [AvailabilityController::class, 'bookingUrl'])->name('booking-url');
    });

/*
|--------------------------------------------------------------------------
| First-party endpoints
|--------------------------------------------------------------------------
|
| What our own booking page and the embed widget call (SPEC §15.1). No token:
| the slug is public and every answer is the same information anyone sees by
| opening the booking page. Not a documented product — these move with the
| frontend, and the widget is shipped by us, so there is nothing to break.
|
| Throttled per IP rather than per token, since there is no token. The widget
| sits on a customer's homepage, so one busy site is normal traffic and the
| limit is set well above what a date picker could ask for.
*/

Route::prefix('internal/{tenant}')
    ->middleware([IdentifyTenantFromPath::class, 'throttle:120,1'])
    ->name('api.internal.')
    ->group(function (): void {
        Route::get('venue', [AvailabilityController::class, 'venue'])->name('venue');
        Route::get('booking-types', [AvailabilityController::class, 'bookingTypes'])->name('booking-types');
        Route::get('days', [AvailabilityController::class, 'days'])->name('days');
        Route::get('times', [AvailabilityController::class, 'times'])->name('times');
        Route::get('availability', [AvailabilityController::class, 'spots'])->name('availability');
    });
