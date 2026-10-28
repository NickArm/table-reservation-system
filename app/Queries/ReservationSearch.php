<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\Channel;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Throwable;

/**
 * The filters behind the reservations list (SPEC §13.1).
 *
 * A query object rather than two copies of the same thirty lines, because the
 * screen and the CSV have to agree. An export that quietly covers a different
 * set of bookings than the list above it is the kind of bug nobody reports and
 * everybody acts on.
 */
final readonly class ReservationSearch
{
    private function __construct(
        public ?string $term,
        public ?string $from,
        public ?string $to,
        public ?string $status,
        public ?string $channel,
        public ?int $bookingTypeId,
        public string $sort,
        private string $timezone,
    ) {}

    public static function fromRequest(Request $request, string $timezone): self
    {
        return new self(
            term: self::text($request, 'q'),
            from: self::date($request, 'from', $timezone),
            to: self::date($request, 'to', $timezone),
            status: self::text($request, 'status'),
            channel: self::text($request, 'channel'),
            bookingTypeId: $request->integer('type') ?: null,
            sort: $request->string('sort')->value() === 'newest' ? 'newest' : 'soonest',
            timezone: $timezone,
        );
    }

    /**
     * @return Builder<Reservation>
     */
    public function apply(): Builder
    {
        $query = Reservation::query();

        if ($this->term !== null) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->term).'%';

            $query->where(function (Builder $query) use ($like): void {
                $query->where('customer_name', 'like', $like)
                    ->orWhere('customer_email', 'like', $like)
                    ->orWhere('customer_phone', 'like', $like)
                    // A code is said out loud over the phone, so an exact
                    // match on it matters more than a partial one.
                    ->orWhere('code', 'like', mb_strtoupper(trim((string) $this->term)).'%');
            });
        }

        // The dates a venue types are its own local dates, and the whole of
        // the last one counts (SPEC §3.5).
        if ($this->from !== null) {
            $query->where('starts_at', '>=', $this->localDay($this->from)->utc());
        }

        if ($this->to !== null) {
            $query->where('starts_at', '<', $this->localDay($this->to)->addDay()->utc());
        }

        if ($this->status !== null && ReservationStatus::tryFrom($this->status) !== null) {
            $query->where('status', $this->status);
        }

        if ($this->channel !== null && Channel::tryFrom($this->channel) !== null) {
            $query->where('channel', $this->channel);
        }

        if ($this->bookingTypeId !== null) {
            $query->where('booking_type_id', $this->bookingTypeId);
        }

        return $this->sort === 'newest'
            ? $query->orderByDesc('created_at')
            : $query->orderBy('starts_at');
    }

    /**
     * Whether anything at all is narrowing the list, so the screen can say
     * "nothing matches" rather than "no bookings yet".
     */
    /**
     * Whether anything the chips cannot express is switched on.
     *
     * Drives whether the advanced panel opens by itself: a filter that is
     * active and out of sight is a filter the owner will blame the data for.
     */
    public function isAdvanced(): bool
    {
        return $this->channel !== null
            || $this->bookingTypeId !== null
            || $this->sort !== 'soonest';
    }

    public function isFiltered(): bool
    {
        return $this->term !== null
            || $this->from !== null
            || $this->to !== null
            || $this->status !== null
            || $this->channel !== null
            || $this->bookingTypeId !== null;
    }

    private function localDay(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, $this->timezone)->startOfDay();
    }

    private static function text(Request $request, string $key): ?string
    {
        $value = trim((string) $request->string($key));

        return $value === '' ? null : $value;
    }

    private static function date(Request $request, string $key, string $timezone): ?string
    {
        $value = self::text($request, $key);

        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, $timezone)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
