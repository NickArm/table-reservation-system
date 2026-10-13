<?php

declare(strict_types=1);

namespace App\Actions\Reservations;

use App\Availability\AvailabilityEngine;
use App\Availability\Exceptions\SpotNotAvailable;
use App\Models\Area;
use App\Models\BookingType;
use App\Models\ReservationHold;
use App\Models\Resource;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a spot aside while someone pays (SPEC §6.4, §24.1).
 *
 * This is where double booking is prevented, so the order is deliberate:
 *
 *   1. lock the resource row, which serialises every request for that spot
 *   2. clear its expired holds, so a lapsed one never blocks a real guest
 *   3. ask availability again, now that nobody else can be mid-flight
 *   4. write
 *
 * Checking before the lock would be a race. MySQL has no exclusion
 * constraints, so the lock plus the unique index on (resource_id, starts_at)
 * is the whole defence.
 */
final readonly class HoldResource
{
    public function __construct(private AvailabilityEngine $engine) {}

    public function handle(
        Resource $resource,
        CarbonImmutable $startsAt,
        int $partySize,
        string $sessionToken,
    ): ReservationHold {
        return DB::transaction(function () use ($resource, $startsAt, $partySize, $sessionToken): ReservationHold {
            $locked = Resource::query()
                ->whereKey($resource->id)
                ->lockForUpdate()
                ->firstOrFail();

            ReservationHold::query()
                ->where('resource_id', $locked->id)
                ->where('expires_at', '<=', now())
                ->delete();

            // Anything read before the lock was taken is now stale.
            $this->engine->forget();

            if (! $this->engine->canBook($locked, $startsAt, $partySize)) {
                throw SpotNotAvailable::taken($locked->label);
            }

            $bookingType = BookingType::query()->findOrFail($locked->booking_type_id);
            $area = Area::query()->find($locked->area_id);

            try {
                $hold = ReservationHold::create([
                    'booking_type_id' => $bookingType->id,
                    'resource_id' => $locked->id,
                    'starts_at' => $startsAt,
                    'ends_at' => $this->engine->endsAtFor($bookingType, $area, $startsAt),
                    'party_size' => $partySize,
                    'session_token' => $sessionToken,
                    'expires_at' => now()->addMinutes((int) $bookingType->hold_duration_minutes),
                ]);
            } catch (QueryException $exception) {
                // The unique index fired, which means a concurrent request
                // won. A lost race, not a server error.
                if ($this->isUniqueViolation($exception)) {
                    throw SpotNotAvailable::taken($locked->label);
                }

                throw $exception;
            }

            $this->engine->forget();

            return $hold;
        });
    }

    /**
     * Releases a hold early — the guest went back a step, or abandoned the
     * payment on purpose.
     */
    public function release(ReservationHold $hold): void
    {
        $hold->delete();

        $this->engine->forget();
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        // 23000 and 23505 cover MySQL, MariaDB, SQLite and Postgres.
        return in_array((string) $exception->getCode(), ['23000', '23505'], true);
    }
}
