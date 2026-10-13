<?php

declare(strict_types=1);

namespace App\Availability;

use App\Enums\StructureScope;
use App\Models\BookingClosure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The closures that touch one date, resolved into instants (SPEC §6.2).
 *
 * Loaded once per lookup and asked many times, because a booking page checks
 * the same handful of closures against twenty sunbeds and twenty slots.
 */
final class ClosureSet
{
    /** @var array<string, array<int, array{0: ?CarbonImmutable, 1: ?CarbonImmutable}>> */
    private array $periods = [];

    /**
     * @param  Collection<int, BookingClosure>  $closures
     */
    public function __construct(Collection $closures, string $date, string $timezone)
    {
        foreach ($closures as $closure) {
            $from = blank($closure->time_from)
                ? null
                : LocalTime::normalized($date, (string) $closure->time_from, $timezone);

            $to = blank($closure->time_to)
                ? null
                : LocalTime::normalized($date, (string) $closure->time_to, $timezone);

            // A closure that ends before it starts runs past midnight, the
            // same way opening hours do: "closed from 22:00 to 02:00".
            if ($from !== null && $to !== null && $to <= $from) {
                $to = $to->addDay();
            }

            $this->periods[$this->key($closure->scope, (int) $closure->scope_id)][] = [$from, $to];
        }
    }

    public static function empty(): self
    {
        return new self(new Collection, '1970-01-01', 'UTC');
    }

    /**
     * Nothing can be booked on this date at all.
     */
    public function closesWholeDay(StructureScope $scope, int $id): bool
    {
        foreach ($this->periods[$this->key($scope, $id)] ?? [] as [$from, $to]) {
            if ($from === null && $to === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * This stretch of time is closed — either because the whole day is, or
     * because a partial closure overlaps it.
     */
    public function blocks(StructureScope $scope, int $id, CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool
    {
        foreach ($this->periods[$this->key($scope, $id)] ?? [] as [$from, $to]) {
            if ($from === null && $to === null) {
                return true;
            }

            $periodStart = $from ?? $startsAt->subCentury();
            $periodEnd = $to ?? $endsAt->addCentury();

            if ($startsAt < $periodEnd && $endsAt > $periodStart) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->periods === [];
    }

    private function key(StructureScope $scope, int $id): string
    {
        return $scope->value.':'.$id;
    }
}
