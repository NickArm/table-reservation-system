<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicSite\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Identifies the browser that is in the middle of booking.
 *
 * Its own token rather than the session id, so that regenerating the session
 * does not orphan a hold somebody is paying for. Kept in the session cookie,
 * which is strictly necessary and therefore needs no cookie banner (§24.7).
 */
trait IdentifiesBookingSession
{
    protected function bookingToken(Request $request): string
    {
        $token = $request->session()->get('booking_token');

        if (! is_string($token) || $token === '') {
            $token = (string) Str::random(48);
            $request->session()->put('booking_token', $token);
        }

        return $token;
    }
}
