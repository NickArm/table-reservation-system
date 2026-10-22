<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Tenants\AcceptPlatformTerms;
use App\Availability\AvailabilityEngine;
use App\Availability\OpeningHoursResolver;
use App\Events\ReservationWasCancelled;
use App\Events\ReservationWasConfirmed;
use App\Listeners\AlertTheVenue;
use App\Listeners\RecordVoucherDelivery;
use App\Listeners\SendBookingVoucher;
use App\Listeners\SendCancellationNotice;
use App\Subscriptions\Entitlements;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
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

        // Scoped rather than a singleton: what an account may do cannot
        // change inside one request, so the plan is worth resolving once —
        // but a queued worker moves between accounts, and a cached answer
        // carried across that boundary is the wrong account's.
        $this->app->scoped(Entitlements::class);
    }

    public function boot(): void
    {
        /*
         * Indexed strings are 191 characters, not 255.
         *
         * InnoDB's older index limit is 767 bytes per column, and utf8mb4
         * spends four bytes a character — so a varchar(255) in an index needs
         * 1020 and the migration fails outright. Newer servers allow 3072, but
         * "newer" is not something shared hosting guarantees, and the failure
         * happens on the first deploy rather than in testing (SPEC §21.2).
         *
         * 191 × 4 = 764. Long enough for every email, slug and label here, and
         * columns that genuinely need more state their own length.
         */
        Schema::defaultStringLength(191);

        // Fail loudly in development instead of silently returning null for a
        // relation nobody loaded, or quietly dropping a typo'd attribute.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Stored in UTC, always. Display converts to the venue's zone at the
        // boundary (SPEC §3.5).
        Date::use(Carbon::class);
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

            /*
             * Whether the terms changed since this account agreed to them
             * (SPEC §12.2). Here rather than in a controller for the same
             * reason as the bell: it belongs to the shell, and a banner that
             * only appeared on whichever screens remembered to look it up
             * would be a banner nobody sees.
             */
            $tenant = app(TenantContext::class)->get();

            $view->with('termsChange', $tenant === null
                ? null
                : app(AcceptPlatformTerms::class)->outstandingFor($tenant));
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
