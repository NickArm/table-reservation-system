<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Dashboard\AreaController;
use App\Http\Controllers\Dashboard\BookingTypeController;
use App\Http\Controllers\Dashboard\CalendarController;
use App\Http\Controllers\Dashboard\ClosureController;
use App\Http\Controllers\Dashboard\DeveloperController;
use App\Http\Controllers\Dashboard\GuestController;
use App\Http\Controllers\Dashboard\ManualBookingController;
use App\Http\Controllers\Dashboard\NotificationController;
use App\Http\Controllers\Dashboard\PaymentSettingsController;
use App\Http\Controllers\Dashboard\PriceRuleController;
use App\Http\Controllers\Dashboard\ReservationController as DashboardReservationController;
use App\Http\Controllers\Dashboard\ReservationListController;
use App\Http\Controllers\Dashboard\ResourceController;
use App\Http\Controllers\Dashboard\TodayController;
use App\Http\Controllers\Dashboard\WidgetController;
use App\Http\Controllers\MailWebhookController;
use App\Http\Controllers\PublicSite\BookingPageController;
use App\Http\Controllers\PublicSite\CheckoutController;
use App\Http\Controllers\PublicSite\EmbedController;
use App\Http\Controllers\PublicSite\HoldController;
use App\Http\Controllers\PublicSite\ReservationController;
use App\Http\Controllers\PublicSite\SearchController;
use App\Http\Controllers\VoucherController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Marketing
|--------------------------------------------------------------------------
*/

Route::view('/', 'marketing.home')->name('home');

/*
|--------------------------------------------------------------------------
| Public booking pages
|--------------------------------------------------------------------------
|
| Path-based, never a subdomain (SPEC §3.2). The tenant comes from the route
| parameter and nothing else.
*/

Route::prefix('reservation/{tenant}')
    ->middleware('tenant.path')
    ->name('public.')
    ->group(function (): void {
        Route::get('/', [BookingPageController::class, 'show'])->name('booking');

        // Everything that could create a booking stops when the venue's own
        // subscription lapses — politely, never with an error (SPEC §4.4).
        Route::middleware('bookings.open')->group(function (): void {
            Route::get('search', SearchController::class)->name('search');

            Route::post('hold', [HoldController::class, 'store'])
                ->middleware('throttle:30,1')
                ->name('hold');
            Route::delete('hold/{hold}', [HoldController::class, 'destroy'])->name('hold.release');

            Route::get('book/{hold}', [ReservationController::class, 'create'])->name('book');
            Route::post('book/{hold}', [ReservationController::class, 'store'])
                ->middleware('throttle:20,1')
                ->name('book.store');
        });

        // Coming back from the gateway. Neither proves anything on its own —
        // the gateway is asked directly before a voucher is shown.
        Route::get('paid/{reservation}', [CheckoutController::class, 'paid'])->name('paid');
        Route::get('cancelled/{reservation}', [CheckoutController::class, 'cancelled'])
            ->name('payment-cancelled');

        // Still reachable after a subscription lapses: the guest booked in
        // good faith and their voucher has to keep working.
        Route::get('booked/{reservation}', [ReservationController::class, 'show'])
            ->name('confirmed');
    });

/*
|--------------------------------------------------------------------------
| The embeddable search bar
|--------------------------------------------------------------------------
|
| `/embed.js` is a static file in public/ — the address has to stay the same
| for ever, because it is pasted into customers' sites, and a hashed bundle
| name would break every one of them on our next deploy.
|
| This address is the bare-iframe alternative (SPEC §9.2): the same bar,
| rendered server-side, for a site builder who cannot add a script tag. It
| needs no JavaScript — the form targets the top window.
*/

Route::get('embed/{tenant}/search', [EmbedController::class, 'search'])
    ->middleware(['tenant.path', 'throttle:120,1'])
    ->name('embed.search');

/*
|--------------------------------------------------------------------------
| Vouchers
|--------------------------------------------------------------------------
|
| Short on purpose. This address goes into a QR that has to scan quickly in
| poor light, so it carries a booking code and a signature and nothing else —
| no tenant slug, no identifier (SPEC §11.2, §32.3).
*/

Route::prefix('v/{code}/{token}')
    ->middleware('throttle:40,1')
    ->group(function (): void {
        Route::get('/', [VoucherController::class, 'show'])->name('voucher');
        Route::get('pdf', [VoucherController::class, 'pdf'])->name('voucher.pdf');
        Route::get('calendar.ics', [VoucherController::class, 'calendar'])->name('voucher.ics');
    });

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function (): void {
    Route::get('register', [RegisterController::class, 'create'])->name('register');
    Route::post('register', [RegisterController::class, 'store']);

    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store']);

    Route::get('forgot-password', [PasswordResetController::class, 'request'])
        ->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'email'])
        ->name('password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'reset'])
        ->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'update'])
        ->name('password.update');
});

Route::middleware('auth')->group(function (): void {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('verify-email', [EmailVerificationController::class, 'notice'])
        ->name('verification.notice');
    Route::get('verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('verify-email/resend', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('verification.send');
});

/*
|--------------------------------------------------------------------------
| Dashboard
|--------------------------------------------------------------------------
|
| No slug in the URL. The tenant comes from the signed-in user, so there is no
| address to edit in order to reach someone else's venue.
*/

Route::prefix('dashboard')
    ->middleware(['auth', 'verified', 'tenant.user'])
    ->name('dashboard.')
    ->group(function (): void {
        Route::get('/', [TodayController::class, 'index'])->name('today');

        // Taking a booking over the telephone (SPEC §13.1).
        Route::get('bookings/new', [ManualBookingController::class, 'create'])
            ->name('bookings.create');
        Route::post('bookings', [ManualBookingController::class, 'store'])
            ->name('bookings.store');

        /*
         * The venue's own guest list (SPEC §28). Scoped to one venue by the
         * guests table's own unique key, and to one account by the tenant
         * scope — no screen here shows one venue another's guests.
         */
        Route::get('guests', [GuestController::class, 'index'])->name('guests.index');
        Route::get('guests/export', [GuestController::class, 'export'])->name('guests.export');
        Route::get('guests/suggest', [GuestController::class, 'suggest'])
            ->middleware('throttle:60,1')
            ->name('guests.suggest');
        Route::get('guests/{guest}', [GuestController::class, 'show'])->name('guests.show');
        Route::patch('guests/{guest}', [GuestController::class, 'update'])->name('guests.update');
        Route::delete('guests/{guest}', [GuestController::class, 'erase'])->name('guests.erase');

        // The shape of the week, for the Monday question (SPEC §13.1).
        Route::get('calendar', CalendarController::class)->name('calendar');

        /*
         * Every booking, searchable. The export is a separate literal route
         * and comes first, so "export" is never read as a reservation.
         */
        Route::get('reservations', [ReservationListController::class, 'index'])
            ->name('reservations.index');
        Route::get('reservations/export', [ReservationListController::class, 'export'])
            ->name('reservations.export');

        // What a venue takes bookings for, and the things it has to give out.
        Route::get('reservations/{reservation}', [DashboardReservationController::class, 'show'])
            ->name('reservations.show');
        Route::patch('reservations/{reservation}/check-in', [DashboardReservationController::class, 'checkIn'])
            ->name('reservations.check-in');
        Route::patch('reservations/{reservation}/no-show', [DashboardReservationController::class, 'noShow'])
            ->name('reservations.no-show');
        Route::patch('reservations/{reservation}/came-anyway', [DashboardReservationController::class, 'undoNoShow'])
            ->name('reservations.came-anyway');
        Route::post('reservations/{reservation}/cancel', [DashboardReservationController::class, 'cancel'])
            ->name('reservations.cancel');

        // Who hears about a booking, and when (SPEC §10.2).
        Route::get('notifications', [NotificationController::class, 'edit'])
            ->name('notifications.edit');
        Route::patch('notifications', [NotificationController::class, 'update'])
            ->name('notifications.update');
        Route::post('notifications/read', [NotificationController::class, 'markAllRead'])
            ->name('notifications.read');

        /*
         * The snippet for the venue's own website (SPEC §9.4).
         *
         * Read-only: nothing is saved, because the settings are the snippet.
         * Storing them would mean two sources of truth for what is on the
         * customer's page, and ours would be the wrong one the moment their
         * developer edited a line.
         */
        Route::get('widget', [WidgetController::class, 'show'])->name('widget');

        /*
         * The account's API token (SPEC §15.2).
         *
         * Revealing is a POST, not a GET: a URL that prints a live secret
         * ends up in the browser history, the proxy log and any screen
         * recording that happened to be running.
         */
        Route::get('developer', [DeveloperController::class, 'show'])->name('developer');
        Route::post('developer/{mode}/reveal', [DeveloperController::class, 'reveal'])
            ->name('developer.reveal');
        Route::post('developer/{mode}/regenerate', [DeveloperController::class, 'regenerate'])
            ->name('developer.regenerate');

        // The venue's own keys, never ours (SPEC §7.4).
        Route::get('payments', [PaymentSettingsController::class, 'edit'])->name('payments.edit');
        Route::patch('payments/{gateway}', [PaymentSettingsController::class, 'update'])
            ->name('payments.update');
        Route::post('payments/{gateway}/test', [PaymentSettingsController::class, 'test'])
            ->name('payments.test');

        Route::get('setup', [BookingTypeController::class, 'index'])
            ->name('booking-types.index');
        Route::post('setup', [BookingTypeController::class, 'store'])
            ->name('booking-types.store');
        Route::get('setup/{bookingType}', [BookingTypeController::class, 'show'])
            ->name('booking-types.show');

        Route::post('setup/{bookingType}/areas', [AreaController::class, 'store'])
            ->name('booking-types.areas.store');
        Route::patch('areas/{area}', [AreaController::class, 'update'])
            ->name('areas.update');
        Route::delete('areas/{area}', [AreaController::class, 'destroy'])
            ->name('areas.destroy');
        Route::patch('areas/{area}/availability', [ResourceController::class, 'bulkAvailability'])
            ->name('areas.availability');

        // Closing looks forward only: it stops new bookings and never touches
        // the ones already paid for (SPEC §24.5).
        Route::post('closures', [ClosureController::class, 'store'])
            ->name('closures.store');
        Route::delete('closures/{closure}', [ClosureController::class, 'destroy'])
            ->name('closures.destroy');
        Route::patch('setup/{bookingType}/stop-today', [ClosureController::class, 'stopToday'])
            ->name('closures.stop-today');
        Route::patch('setup/{bookingType}/resume-today', [ClosureController::class, 'resumeToday'])
            ->name('closures.resume-today');

        // Optional everywhere: a venue with one price never comes here.
        Route::post('price-rules', [PriceRuleController::class, 'store'])
            ->name('price-rules.store');
        Route::delete('price-rules/{priceRule}', [PriceRuleController::class, 'destroy'])
            ->name('price-rules.destroy');

        Route::post('resources', [ResourceController::class, 'store'])
            ->name('resources.store');
        Route::patch('resources/{resource}/toggle', [ResourceController::class, 'toggle'])
            ->name('resources.toggle');
        Route::delete('resources/{resource}', [ResourceController::class, 'destroy'])
            ->name('resources.destroy');
    });

/*
|--------------------------------------------------------------------------
| Gateway webhooks
|--------------------------------------------------------------------------
|
| One endpoint per venue, because each venue signs with its own secret
| (SPEC §7.4). No session, no CSRF token — a gateway has neither — so the
| signature is the only thing standing between us and anybody with curl.
*/

Route::post('webhooks/{gateway}/{tenantUuid}', WebhookController::class)
    ->whereIn('gateway', ['stripe', 'paypal'])
    ->name('webhooks');

/*
 * Delivery notices from the mail provider (SPEC §10.3). The secret sits in the
 * path because providers post these as plain JSON with no signature of their
 * own, and because the endpoint reveals nothing: it matches message ids we
 * recorded ourselves and answers 200 to everything else.
 *
 * The gateway route above is constrained to the gateways that actually exist
 * for this reason: `webhooks/{gateway}/{tenantUuid}` otherwise matches this
 * address too, with `mail` read as the gateway, and every delivery notice is
 * answered by the wrong controller.
 */
Route::post('webhooks/mail/{secret}', MailWebhookController::class)
    ->name('webhooks.mail');
