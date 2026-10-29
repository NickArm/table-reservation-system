<?php

declare(strict_types=1);

use App\Enums\PlatformDocumentType;
use App\Enums\VenueDocumentType;
use App\Http\Controllers\Admin\AuditController as AdminAuditController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\LegalController as AdminLegalController;
use App\Http\Controllers\Admin\OverviewController as AdminOverviewController;
use App\Http\Controllers\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Admin\SubscriptionsController as AdminSubscriptionsController;
use App\Http\Controllers\Admin\SystemController as AdminSystemController;
use App\Http\Controllers\Admin\TenantController as AdminTenantController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\BillingWebhookController;
use App\Http\Controllers\Dashboard\AccountDeletionController;
use App\Http\Controllers\Dashboard\AccountExportController;
use App\Http\Controllers\Dashboard\AreaController;
use App\Http\Controllers\Dashboard\BillingCheckoutController;
use App\Http\Controllers\Dashboard\BillingController;
use App\Http\Controllers\Dashboard\BookingTypeController;
use App\Http\Controllers\Dashboard\BrandingController;
use App\Http\Controllers\Dashboard\CalendarController;
use App\Http\Controllers\Dashboard\ClosureController;
use App\Http\Controllers\Dashboard\DeveloperController;
use App\Http\Controllers\Dashboard\GuestController;
use App\Http\Controllers\Dashboard\LegalController as DashboardLegalController;
use App\Http\Controllers\Dashboard\ManualBookingController;
use App\Http\Controllers\Dashboard\NotificationController;
use App\Http\Controllers\Dashboard\PaymentSettingsController;
use App\Http\Controllers\Dashboard\PriceRuleController;
use App\Http\Controllers\Dashboard\ReportController;
use App\Http\Controllers\Dashboard\ReservationController as DashboardReservationController;
use App\Http\Controllers\Dashboard\ReservationListController;
use App\Http\Controllers\Dashboard\ResourceController;
use App\Http\Controllers\Dashboard\SettingsController;
use App\Http\Controllers\Dashboard\SetupController;
use App\Http\Controllers\Dashboard\TermsAcceptanceController;
use App\Http\Controllers\Dashboard\TodayController;
use App\Http\Controllers\Dashboard\VenueProfileController;
use App\Http\Controllers\Dashboard\WidgetController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\MailWebhookController;
use App\Http\Controllers\PublicSite\BookingPageController;
use App\Http\Controllers\PublicSite\CheckoutController;
use App\Http\Controllers\PublicSite\EmbedController;
use App\Http\Controllers\PublicSite\HoldController;
use App\Http\Controllers\PublicSite\ReservationController;
use App\Http\Controllers\PublicSite\SearchController;
use App\Http\Controllers\PublicSite\SitemapController;
use App\Http\Controllers\PublicSite\VenueLegalController;
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
 * Every booking page worth indexing (SPEC §33.2). Structured data tells a
 * search engine what a page is; this tells it the page exists — otherwise a
 * brand new venue waits for somebody to link to it.
 */
Route::get('sitemap.xml', SitemapController::class)->name('sitemap');

/*
|--------------------------------------------------------------------------
| The platform's legal pages (SPEC §12.2)
|--------------------------------------------------------------------------
|
| Public, indexable, and reachable without an account — they have to be, or
| the links in the sign-up form go nowhere for exactly the person who needs to
| read them before signing up.
|
| The addresses come from the enum rather than being written here, because
| they end up in contracts, in emails and on other people's websites: /terms
| is a promise, not a routing detail.
*/

Route::get('{type}', LegalController::class)
    ->whereIn('type', array_map(
        static fn (PlatformDocumentType $case): string => $case->path(),
        PlatformDocumentType::cases(),
    ))
    ->name('legal');

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

        /*
         * The venue's own terms and cancellation policy (SPEC §12.1).
         *
         * Outside the bookings-open group on purpose: a venue whose
         * subscription has lapsed still has guests holding confirmations that
         * link here, and a term somebody cannot go back and read is a term
         * that does not hold.
         */
        Route::get('legal/{type}', VenueLegalController::class)
            ->whereIn('type', VenueDocumentType::paths())
            ->name('legal');

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
| Super admin
|--------------------------------------------------------------------------
|
| The platform's own screens (SPEC §13.2). Everything here reads across
| accounts, which is why `super-admin` is the one middleware that switches
| tenant scoping off for a whole request — and why it answers 404 rather than
| 403 to everybody else. Confirming that an admin area exists is an
| invitation, and there is nothing here a venue owner needs.
|
| Deliberately *not* inside the dashboard group: a super admin has no tenant
| of their own, so `tenant.user` would refuse them at the door.
*/

Route::prefix('admin')
    ->middleware(['auth', 'verified', 'super-admin'])
    ->name('admin.')
    ->group(function (): void {
        Route::get('/', AdminOverviewController::class)->name('overview');

        Route::get('tenants', [AdminTenantController::class, 'index'])->name('tenants.index');
        Route::get('tenants/{tenant}', [AdminTenantController::class, 'show'])->name('tenants.show');

        /*
         * The three that change what an account has. Each one requires a
         * written reason and lands in the audit log with a name on it
         * (SPEC §4.1, §2.1).
         */
        Route::post('tenants/{tenant}/extend', [AdminTenantController::class, 'extend'])
            ->name('tenants.extend');
        Route::post('tenants/{tenant}/suspend', [AdminTenantController::class, 'suspend'])
            ->name('tenants.suspend');
        Route::post('tenants/{tenant}/restore', [AdminTenantController::class, 'restore'])
            ->name('tenants.restore');

        /*
         * Becoming the venue. A POST, not a link: a GET that changes who you
         * are ends up in browser history and can be triggered by an image
         * tag on any page the admin happens to be reading.
         */
        Route::post('tenants/{tenant}/impersonate', [ImpersonationController::class, 'store'])
            ->name('tenants.impersonate');

        Route::get('plans', [AdminPlanController::class, 'index'])->name('plans.index');
        Route::get('plans/new', [AdminPlanController::class, 'create'])->name('plans.create');
        Route::post('plans', [AdminPlanController::class, 'store'])->name('plans.store');
        Route::get('plans/{plan}', [AdminPlanController::class, 'edit'])->name('plans.edit');
        Route::patch('plans/{plan}', [AdminPlanController::class, 'update'])->name('plans.update');

        /*
         * The legal texts, editable without a deploy (SPEC §12.2). That is
         * the requirement: the wording comes from a lawyer, will arrive
         * twice, and probably after the first customer has signed up.
         */
        Route::get('legal', [AdminLegalController::class, 'index'])->name('legal.index');
        Route::get('legal/{type}', [AdminLegalController::class, 'edit'])->name('legal.edit');
        Route::patch('legal/{type}', [AdminLegalController::class, 'update'])->name('legal.update');

        /*
         * Where the money is, and whether the parts nobody watches are
         * working (SPEC §13.2). The second one exists because a missing cron
         * does not throw, does not log and does not email anybody.
         */
        Route::get('subscriptions', AdminSubscriptionsController::class)->name('subscriptions');
        Route::get('system', AdminSystemController::class)->name('system');

        Route::get('audit', AdminAuditController::class)->name('audit');
    });

/*
 * Ending an impersonation.
 *
 * Outside the group above, because while impersonating the platform screens
 * are closed — one identity at a time — and a route behind them would leave
 * no way out except clearing cookies.
 */
Route::post('stop-impersonating', [ImpersonationController::class, 'destroy'])
    ->middleware('auth')
    ->name('impersonate.stop');

/*
|--------------------------------------------------------------------------
| Dashboard
|--------------------------------------------------------------------------
|
| No slug in the URL. The tenant comes from the signed-in user, so there is no
| address to edit in order to reach someone else's venue.
*/

Route::prefix('dashboard')
    ->middleware(['auth', 'verified', 'tenant.user', 'account.can-write'])
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

        // How the season went, for the October question (SPEC §36).
        Route::get('reports', ReportController::class)->name('reports');

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
         * Billing (SPEC §4).
         *
         * Outside the read-only lock on purpose: an account that has run out
         * has to be able to reach the page where it would pay. Locking
         * somebody out of that is the one way to guarantee they do not.
         */
        Route::get('billing', [BillingController::class, 'show'])->name('billing');

        /*
         * Paying for it (SPEC §4.3).
         *
         * These three are exempt from the read-only lock for the same reason
         * the page is: the account we are locking is the account we want to
         * take money from.
         *
         * The return is a GET because a payment provider redirects a browser
         * back, and a browser follows a redirect with a GET. It is not
         * trusted on that account — the controller asks the provider what
         * happened rather than believing the address.
         */
        Route::post('billing/checkout/{plan}', [BillingCheckoutController::class, 'start'])
            ->name('billing.checkout');
        Route::get('billing/returned/{provider}', [BillingCheckoutController::class, 'returned'])
            ->name('billing.returned');
        Route::post('billing/cancel', [BillingCheckoutController::class, 'cancel'])
            ->name('billing.cancel');

        /*
         * Agreeing to a new version of the terms (SPEC §12.2). Exempt from
         * the read-only lock: an expired account is still shown the banner,
         * and refusing the one button on it would be absurd.
         */
        Route::post('terms/accept', TermsAcceptanceController::class)
            ->name('terms.accept');

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
         * The venue's own terms and cancellation policy (SPEC §12.1). One
         * screen, two boxes, pre-filled with text that already works — a
         * blank textarea headed "Terms of Booking" produces either nothing or
         * a paragraph copied off a competitor.
         */
        /*
         * The account itself, and the one irreversible thing in the product
         * (SPEC §13.1, §29).
         */
        /*
         * How the booking page looks (SPEC §5.5, §27). Under Setup rather
         * than in Settings, because it is something an owner does once while
         * setting up and then forgets — and Settings is where the account
         * itself lives.
         */
        /*
         * The venue's public profile (SPEC §33.1). The fields have been in
         * the database since M0 and the booking page has read them since M4;
         * this is the screen that lets an owner fill them in.
         */
        Route::get('profile', [VenueProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('profile', [VenueProfileController::class, 'update'])->name('profile.update');

        Route::get('branding', [BrandingController::class, 'edit'])->name('branding.edit');
        Route::patch('branding', [BrandingController::class, 'update'])->name('branding.update');

        /*
         * One page for everything set once (SPEC §1.1). The rail holds what
         * is touched during service; this holds the rest, so the menu stays
         * short as the settings keep arriving.
         */
        Route::get('setup', SetupController::class)->name('setup');

        Route::get('settings', SettingsController::class)->name('settings');
        Route::get('settings/export', AccountExportController::class)->name('account.export');

        /*
         * Asking for deletion. Owners only, enforced by the form request, and
         * **not** exempt from the read-only lock — an account that has run out
         * is an account that should renew or leave, and the lock has nothing
         * useful to say about the second.
         */
        Route::post('settings/delete', [AccountDeletionController::class, 'store'])
            ->name('account.destroy');

        Route::get('legal', [DashboardLegalController::class, 'edit'])->name('legal.edit');
        Route::patch('legal/{type}', [DashboardLegalController::class, 'update'])
            ->whereIn('type', VenueDocumentType::paths())
            ->name('legal.update');

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

        /*
         * What there is to book. One level inside Setup rather than at it,
         * because Setup itself is now the page that lists everything — and
         * two screens cannot share a URL. The names are unchanged, so every
         * link to these goes on working.
         */
        Route::get('setup/tables', [BookingTypeController::class, 'index'])
            ->name('booking-types.index');
        Route::post('setup/tables', [BookingTypeController::class, 'store'])
            ->name('booking-types.store');
        Route::get('setup/tables/{bookingType}', [BookingTypeController::class, 'show'])
            ->name('booking-types.show');

        Route::post('setup/tables/{bookingType}/areas', [AreaController::class, 'store'])
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
        Route::patch('setup/tables/{bookingType}/stop-today', [ClosureController::class, 'stopToday'])
            ->name('closures.stop-today');
        Route::patch('setup/tables/{bookingType}/resume-today', [ClosureController::class, 'resumeToday'])
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

/*
 * Our own provider, about subscriptions (SPEC §4.3).
 *
 * Registered before the per-venue route below and with no account in the
 * path, because this is the platform's single Stripe or PayPal account
 * rather than a venue's. Which account a notice concerns is read out of the
 * notice itself, after it has been verified.
 *
 * This is where a renewal eleven months from now arrives, and nowhere else:
 * nobody is sitting in a browser when a card is charged for the second time.
 */
Route::post('webhooks/billing/{provider}', BillingWebhookController::class)
    ->whereIn('provider', ['stripe', 'paypal'])
    ->name('webhooks.billing');

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
