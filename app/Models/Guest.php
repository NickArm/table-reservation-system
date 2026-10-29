<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Initials;
use Database\Factories\GuestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One person, as one venue knows them (SPEC §28).
 *
 * `internal_notes` is hidden by default. It is the venue's private view of a
 * guest — "argues about the bill", "always late" — and it must never reach an
 * email, an API response or the guest's own voucher by accident.
 */
#[Fillable([
    'name', 'email', 'phone', 'locale',
    'marketing_consent', 'marketing_consent_at',
    'internal_notes', 'tags',
])]
#[Hidden(['internal_notes'])]
class Guest extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<GuestFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'marketing_consent_at' => 'immutable_datetime',
            'anonymized_at' => 'immutable_datetime',
            'marketing_consent' => 'boolean',
            'tags' => 'array',
        ];
    }

    /**
     * @return HasMany<Reservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /**
     * Normalised the same way everywhere, because the unique key depends on
     * it: a guest who types `Nick@Example.com ` on the phone and
     * `nick@example.com` online is one person.
     */
    public static function normalizeEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email === '' ? null : $email;
    }

    /**
     * Name, email or phone. Deliberately a plain LIKE: a venue has hundreds of
     * guests, not millions, and a search that needs explaining is worse than
     * one that is merely simple (SPEC §1.1).
     *
     * @param  Builder<Guest>  $query
     * @return Builder<Guest>
     */
    public function scopeMatching(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $query->where(function (Builder $query) use ($like): void {
            $query->where('name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like);
        });
    }

    /**
     * @param  Builder<Guest>  $query
     * @return Builder<Guest>
     */
    public function scopeRepeat(Builder $query): Builder
    {
        return $query->where('bookings_count', '>=', 2);
    }

    /**
     * @param  Builder<Guest>  $query
     * @return Builder<Guest>
     */
    public function scopeTrouble(Builder $query): Builder
    {
        return $query->where('no_show_count', '>=', 1);
    }

    public function isAnonymized(): bool
    {
        return $this->anonymized_at !== null;
    }

    public function isRepeat(): bool
    {
        return (int) $this->bookings_count >= 2;
    }

    /**
     * "3rd visit" — what goes next to a name on the Today screen (SPEC §28.3).
     *
     * Counts the booking in hand, so a guest arriving for their third is shown
     * as their third and not as somebody with two.
     */
    public function visitLabel(): ?string
    {
        $count = (int) $this->bookings_count;

        if ($count < 2) {
            return null;
        }

        return $count.match ($count % 100) {
            11, 12, 13 => 'th',
            default => match ($count % 10) {
                1 => 'st',
                2 => 'nd',
                3 => 'rd',
                default => 'th',
            },
        }.' visit';
    }

    /**
     * Two letters for the list, where a photograph will never exist.
     *
     * A column of initials gives the eye something to land on while
     * scrolling, which a column of names in the same weight does not.
     */
    public function initials(): string
    {
        return Initials::of($this->name);
    }

    /**
     * The warning worth interrupting somebody for, or nothing at all.
     */
    public function warning(): ?string
    {
        $noShows = (int) $this->no_show_count;

        if ($noShows < 1) {
            return null;
        }

        return $noShows === 1 ? '1 no-show' : $noShows.' no-shows';
    }

    /**
     * @return list<string>
     */
    public function tagList(): array
    {
        $tags = $this->tags;

        if (! is_array($tags)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $tag): string => trim((string) $tag), $tags),
            static fn (string $tag): bool => $tag !== ''
        ));
    }
}
