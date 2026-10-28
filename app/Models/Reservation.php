<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Channel;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Observers\ReservationObserver;
use Carbon\CarbonImmutable;
use Database\Factories\ReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One booking (SPEC §8).
 */
#[ObservedBy([ReservationObserver::class])]
#[Fillable([
    'customer_name', 'customer_email', 'customer_phone', 'locale',
    'party_size', 'starts_at', 'ends_at', 'notes',
    'marketing_consent', 'marketing_consent_at',
    'amount_cents', 'deposit_label_snapshot', 'cancellation_policy_snapshot',
    'status', 'payment_status', 'channel', 'referrer_domain',
    'resource_snapshot',
])]
class Reservation extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ReservationFactory> */
    use HasFactory;

    /** Characters a person can read out over the phone: no 0/O, no 1/I. */
    private const CODE_ALPHABET = '23456789ACDEFGHJKLMNPQRSTUVWXYZ';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(static function (self $reservation): void {
            if (blank($reservation->getAttribute('uuid'))) {
                $reservation->setAttribute('uuid', (string) Str::uuid7());
            }

            if (blank($reservation->getAttribute('code'))) {
                $reservation->setAttribute('code', self::generateCode());
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'checked_in_at' => 'immutable_datetime',
            'terms_accepted_at' => 'immutable_datetime',
            'voucher_sent_at' => 'immutable_datetime',
            'reminder_sent_at' => 'immutable_datetime',
            'marketing_consent_at' => 'immutable_datetime',
            'marketing_consent' => 'boolean',
            'resource_snapshot' => 'array',
            'status' => ReservationStatus::class,
            'payment_status' => PaymentStatus::class,
            'channel' => Channel::class,
        ];
    }

    /**
     * @return BelongsTo<BookingType, $this>
     */
    public function bookingType(): BelongsTo
    {
        return $this->belongsTo(BookingType::class);
    }

    /**
     * @return BelongsTo<Guest, $this>
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * @return BelongsTo<Area, $this>
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /**
     * @return BelongsTo<Resource, $this>
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    /**
     * Reservations that actually hold their resource.
     *
     * @param  Builder<Reservation>  $query
     * @return Builder<Reservation>
     */
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('status', ReservationStatus::blocking());
    }

    /**
     * Bookings a guest could still turn up for.
     *
     * The one definition of "somebody is counting on this", used wherever
     * something is about to be taken away: an account being deleted (§29.3),
     * a table being removed. Checked-in and completed bookings are in the
     * past as far as this is concerned — only what is still ahead can be
     * orphaned, and only that is worth refusing for.
     *
     * @param  Builder<Reservation>  $query
     * @return Builder<Reservation>
     */
    public function scopeStillToCome(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [
                ReservationStatus::Confirmed->value,
                ReservationStatus::CheckedIn->value,
            ])
            ->where('starts_at', '>=', now());
    }

    /**
     * Overlapping a window, with the turnover buffer applied on both sides.
     *
     * A booking from 10:00 to 14:00 with a 30 minute buffer makes the next
     * arrival 14:30 — and equally stops someone finishing at 10:00 (SPEC
     * §6.2.1).
     *
     * @param  Builder<Reservation>  $query
     * @return Builder<Reservation>
     */
    public function scopeOverlapping(
        Builder $query,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        int $bufferMinutes = 0,
    ): Builder {
        return $query
            ->where('starts_at', '<', $endsAt->addMinutes($bufferMinutes))
            ->where('ends_at', '>', $startsAt->subMinutes($bufferMinutes));
    }

    /**
     * Local time at the venue, for anything a person reads (SPEC §3.5).
     */
    public function startsAtLocal(string $timezone): CarbonImmutable
    {
        return $this->starts_at->setTimezone($timezone);
    }

    /**
     * What the spot was made of when it was booked, from the snapshot rather
     * than from today's data — the voucher has to stay true even after the
     * owner rebuilds a set (SPEC §14).
     */
    public function snapshotContents(): string
    {
        $components = $this->resource_snapshot['components'] ?? null;

        if (! is_array($components)) {
            return '';
        }

        return collect($components)
            ->map(function (array $part): string {
                $quantity = (int) ($part['qty'] ?? 1);
                $label = (string) ($part['label'] ?? '');

                return $quantity > 1 ? "{$quantity} {$label}s" : $label;
            })
            ->filter()
            ->implode(' · ');
    }

    public function isCheckedIn(): bool
    {
        return $this->checked_in_at !== null;
    }

    /**
     * When the table is theirs until, in the venue's own time.
     *
     * The pair of `startsAtLocal`. A run sheet that says 13:00 and nothing
     * else leaves whoever is reading it to work out when the table frees up,
     * which is the one thing they are trying to find out (SPEC §3.5).
     */
    public function endsAtLocal(string $timezone): CarbonImmutable
    {
        return $this->ends_at->setTimezone($timezone);
    }

    private static function generateCode(): string
    {
        do {
            $code = '';

            foreach (range(1, 6) as $ignored) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }

            // Codes are said out loud, so they are unique across the whole
            // platform rather than per venue — "is that your code or theirs?"
            // is a conversation nobody should have.
            $taken = self::query()->acrossTenants()->where('code', $code)->exists();
        } while ($taken);

        return $code;
    }
}
