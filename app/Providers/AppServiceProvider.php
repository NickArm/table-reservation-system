<?php

declare(strict_types=1);

namespace App\Providers;

use App\Availability\AvailabilityEngine;
use App\Availability\OpeningHoursResolver;
use App\Events\ReservationWasCancelled;
use App\Events\ReservationWasConfirmed;
use App\Listeners\AlertTheVenue;
use App\Listeners\RecordVoucherDelivery;
use App\Listeners\SendBookingVoucher;
use App\Listeners\SendCancellationNotice;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One context per request, command or job. Everything that decides
        // which venue's data is visible reads from this instance.
        $this->app->singleton(TenantContext::class);

        // One availability engine per request, so that the day it has loaded
        // is shared rather than rebuilt by every caller — and so that
        // forget(), after a write, clears it for all of them.
        $this->app->scoped(OpeningHoursResolver::class);
        $this->app->scoped(AvailabilityEngine::class);
    }

    public function boot(): void
    {
        // Fail loudly in development instead of silently returning null for a
        // relation nobody loaded, or quietly dropping a typo'd attribute.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Stored in UTC, always. Display converts to the venue's zone at the
        // boundary (SPEC §3.5).
        Date::use(\Illuminate\Support\Carbon::class);
        date_default_timezone_set('UTC');

        $this->registerListeners();

        // The bell is part of the dashboard shell rather than of any one
        // screen, so it is filled here instead of in nine controllers
        // (SPEC §10.2).
        View::composer('layouts.dashboard', function (ViewContract $view): void {
            $user = auth()->user();

            $view->with('bell', $user === null
                ? collect()
                : $user->unreadNotifications()->latest()->limit(8)->get());
        });
    }

    /**
     * Listeners are declared rather than discovered.
     *
     * Automatic discovery walks app/Listeners on every boot unless the cache
     * has been warmed, which on the shared hosting this is meant to run on is
     * a filesystem scan per request. More to the point, a list is something a
     * person can read: what happens after a booking is confirmed should be
     * answerable without a directory listing (SPEC §31.4).
     */
    private function registerListeners(): void
    {
        $listeners = [
            ReservationWasConfirmed::class => [
                SendBookingVoucher::class,
                AlertTheVenue::class,
            ],
            ReservationWasCancelled::class => [
                SendCancellationNotice::class,
            ],

            // Records which message carried which booking, so that a bounce
            // notice can be traced back to it (SPEC §10.3).
            MessageSent::class => [
                RecordVoucherDelivery::class,
            ],
        ];

        foreach ($listeners as $event => $handlers) {
            foreach ($handlers as $handler) {
                Event::listen($event, $handler);
            }
        }
    }
}
