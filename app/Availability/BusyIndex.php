<?php

declare(strict_types=1);

namespace App\Availability;

use Carbon\CarbonImmutable;

/**
 * Everything already claimed on one day, per resource (SPEC §6.4).
 *
 * Built from two queries — reservations and live holds — and then asked in
 * memory. A day's worth of bookings for one venue is tens of rows, while a
 * booking page asks "is this free?" hundreds of times: twenty sunbeds against
 * twenty arrival times. Doing that arithmetic in PHP is the difference
 * between one page load and four hundred queries.
 */
final class BusyIndex
{
    /** @var array<int, array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>> */
    private array $intervals = [];

    public function add(int $resourceId, CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        $this->intervals[$resourceId][] = [$startsAt, $endsAt];
    }

    /**
     * The turnover buffer applies on both sides: a booking that ends at 14:00
     * with a 30 minute buffer frees the spot at 14:30, and equally nothing
     * may end later than 13:30 before it.
     */
    public function isFree(
        int $resourceId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        int $bufferMinutes = 0,
    ): bool {
        $from = $startsAt->subMinutes($bufferMinutes);
        $to = $endsAt->addMinutes($bufferMinutes);

        foreach ($this->intervals[$resourceId] ?? [] as [$busyStart, $busyEnd]) {
            if ($busyStart < $to && $busyEnd > $from) {
                return false;
            }
        }

        return true;
    }

    public function isBusy(
        int $resourceId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        int $bufferMinutes = 0,
    ): bool {
        return ! $this->isFree($resourceId, $startsAt, $endsAt, $bufferMinutes);
    }

    /**
     * @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function forResource(int $resourceId): array
    {
        return $this->intervals[$resourceId] ?? [];
    }

    public function count(): int
    {
        return array_sum(array_map('count', $this->intervals));
    }
}
