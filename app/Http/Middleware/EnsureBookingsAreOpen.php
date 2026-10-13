<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Venue;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stops new bookings when the subscription has lapsed.
 *
 * It renders a short, calm page rather than an error (SPEC §4.4): the guest did
 * nothing wrong, and existing bookings stay valid.
 */
final class EnsureBookingsAreOpen
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->getOrFail();

        if (! $tenant->acceptsBookings()) {
            return response()->view('public.unavailable', [
                'tenant' => $tenant,
                'venue' => Venue::query()->first(),
            ], 503);
        }

        return $next($request);
    }
}
