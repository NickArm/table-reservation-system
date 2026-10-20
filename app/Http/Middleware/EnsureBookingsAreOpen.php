<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\PlanLimit;
use App\Models\Tenant;
use App\Models\Venue;
use App\Subscriptions\Entitlements;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stops new bookings when the venue's own subscription has run out, or when
 * its plan's monthly allowance is full (SPEC §4.4, §4.5).
 *
 * Two things it is careful about.
 *
 * It renders a short, calm page rather than an error. The guest did nothing
 * wrong, the venue is probably about to fix it, and a stack trace or a bare
 * 404 on a taverna's booking page is worse for them than a sentence.
 *
 * And it says the same thing in every form. It guards the public pages *and*
 * the first-party endpoints the embed widget calls, so a widget on a
 * customer's homepage shows the same sentence instead of an empty box or a
 * console error — which was the previous behaviour, and meant an expired
 * venue's widget cheerfully went on offering times nobody could book.
 *
 * What it deliberately does not touch: a booking that already exists. Those
 * stay visible and valid, and the voucher keeps working. The guest paid in
 * good faith, and whatever is wrong is between the venue and us.
 */
final class EnsureBookingsAreOpen
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Entitlements $entitlements,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->getOrFail();

        if (! $tenant->acceptsBookings()) {
            return $this->closed($request, $tenant);
        }

        /*
         * The monthly allowance, where the plan has one. Presented to the
         * guest as "not available" rather than as the venue's billing
         * problem: a tourist on a beach has no use for the distinction, and
         * the venue would rather not advertise it.
         *
         * The owner is told plainly, in their own dashboard, by the actions
         * that refuse the write.
         */
        if (! $this->entitlements->hasRoomFor(PlanLimit::ReservationsPerMonth)) {
            return $this->closed($request, $tenant);
        }

        return $next($request);
    }

    private function closed(Request $request, Tenant $tenant): Response
    {
        $message = 'Online reservations are temporarily unavailable.';

        // The widget reads JSON and draws its own sentence, so it gets the
        // same meaning in the shape it understands rather than a page it
        // cannot show.
        if ($request->is('api/*') || $request->expectsJson()) {
            return new JsonResponse(
                ['error' => ['code' => 'bookings_closed', 'message' => $message]],
                503,
            );
        }

        return response()->view('public.unavailable', [
            'tenant' => $tenant,
            'venue' => Venue::query()->first(),
            'message' => $message,
        ], 503);
    }
}
