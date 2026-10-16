<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Api\V1\Payload;
use App\Availability\AvailabilityEngine;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BookingUrlRequest;
use App\Http\Requests\Api\DaysRequest;
use App\Http\Requests\Api\SpotsRequest;
use App\Http\Requests\Api\TimesRequest;
use App\Models\BookingType;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;

/**
 * Every read the public API and the embed widget make (SPEC §15.1, §15.2).
 *
 * One controller serving both route groups on purpose. The widget is the
 * single biggest consumer of this data, and if it had endpoints of its own
 * they would drift from the documented ones — then the shape a customer's
 * developer reads about would be subtly not the shape we ourselves rely on.
 * The two groups differ only in how the tenant was bound before arriving
 * here: a bearer token for /api/v1, the slug in the path for /api/internal.
 *
 * Everything is public availability. No personal data, no writes.
 */
final class AvailabilityController extends Controller
{
    public function __construct(private readonly AvailabilityEngine $engine) {}

    public function venue(): JsonResponse
    {
        $venue = Venue::query()->firstOrFail();

        return $this->ok(Payload::venue($venue));
    }

    public function bookingTypes(): JsonResponse
    {
        return $this->ok(Payload::bookingTypes(
            BookingType::query()->active()->get()
        ));
    }

    public function days(DaysRequest $request): JsonResponse
    {
        $bookingType = $request->bookingType();

        return $this->ok(
            Payload::days($this->engine->month(
                $bookingType,
                $request->month(),
                $request->partySize(),
            )),
            ['booking_type' => $bookingType->slug, 'month' => $request->month()],
        );
    }

    public function times(TimesRequest $request): JsonResponse
    {
        $bookingType = $request->bookingType();

        return $this->ok(
            Payload::times($this->engine->times(
                $bookingType,
                $request->day(),
                $request->partySize(),
            )),
            ['booking_type' => $bookingType->slug, 'date' => $request->day()],
        );
    }

    public function spots(SpotsRequest $request): JsonResponse
    {
        $bookingType = $request->bookingType();
        $venue = Venue::query()->firstOrFail();

        $startsAt = $request->startsAt((string) $venue->timezone);

        return $this->ok(
            Payload::availability($this->engine->at(
                $bookingType,
                $startsAt,
                $request->partySize(),
            )),
            [
                'booking_type' => $bookingType->slug,
                'date' => $request->day(),
                'starts_at' => $startsAt->toIso8601String(),
            ],
        );
    }

    /**
     * Where a custom form should send the visitor once they have chosen.
     *
     * Handing this back rather than letting the caller build it keeps one
     * copy of the URL contract: the booking and the payment always happen
     * here, where the availability engine and the hold live (SPEC §15.2).
     */
    public function bookingUrl(BookingUrlRequest $request): JsonResponse
    {
        $bookingType = $request->bookingType();
        $venue = Venue::query()->firstOrFail();

        return $this->ok([
            'url' => route('public.search', array_filter([
                $venue->tenant,
                'type' => $bookingType->slug,
                'people' => $request->partySize(),
                'date' => $request->has('date') ? (string) $request->string('date') : null,
                'time' => $request->has('time') ? (string) $request->string('time') : null,
                'channel' => 'api',
            ], static fn (mixed $value): bool => $value !== null)),
        ]);
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function ok(array $data, array $meta = []): JsonResponse
    {
        return new JsonResponse($meta === []
            ? ['data' => $data]
            : ['data' => $data, 'meta' => $meta]);
    }
}
