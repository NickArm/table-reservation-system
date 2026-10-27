<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VenueCategory;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\VenueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * The physical place: address, branding and the public profile that feeds the
 * booking page and its structured data (SPEC §33).
 *
 * Created automatically with the account and invisible in the interface while
 * an account has only one (SPEC §1.3).
 */
#[Fillable([
    'name', 'description',
    'address', 'city', 'postal_code', 'region', 'country',
    'latitude', 'longitude',
    'phone', 'public_email', 'website_url',
    'venue_category', 'cuisine', 'price_range',
    'timezone',
    'accent_color', 'hero_overlay', 'logo_path', 'hero_path',
    'is_indexable', 'meta_title', 'meta_description',
    'notification_emails', 'notify_on_booking', 'daily_summary_hour',
    'quiet_hours_from', 'quiet_hours_to', 'reminder_hours_before',
])]
class Venue extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<VenueFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'venue_category' => VenueCategory::class,
            'hero_overlay' => 'integer',
            'is_indexable' => 'boolean',
            'social' => 'array',
            'settings' => 'array',
            'notification_emails' => 'array',
            'notify_on_booking' => 'boolean',
            'daily_summary_hour' => 'integer',
            'reminder_hours_before' => 'integer',
            'widget_last_seen_at' => 'immutable_datetime',
        ];
    }

    /**
     * The widget has been seen working on a site (SPEC §9.4).
     *
     * Deliberately recent rather than ever: a sighting from last March tells
     * an owner nothing about whether the snippet is on the page today.
     */
    public function widgetIsLive(): bool
    {
        return $this->widget_last_seen_at !== null
            && $this->widget_last_seen_at->gt(now()->subDays(7));
    }

    /**
     * Local time at the venue — the only time anybody here thinks in.
     */
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone);
    }

    /**
     * Who is told about a new booking.
     *
     * The owner always is, whatever the settings say: an account with no
     * reachable address is an account whose bookings quietly go unnoticed.
     *
     * @return list<string>
     */
    public function notificationRecipients(): array
    {
        $extra = array_filter(
            (array) ($this->notification_emails ?? []),
            static fn (mixed $email): bool => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        );

        $owners = User::query()
            ->whereHas('tenants', fn (Builder $query) => $query->whereKey($this->tenant_id))
            ->pluck('email')
            ->all();

        /** @var list<string> $all */
        $all = array_values(array_unique(array_merge($owners, array_values($extra))));

        return $all;
    }

    /**
     * Whether a given moment falls inside the venue's quiet hours.
     *
     * Written to handle a window that crosses midnight, which is the normal
     * case: nobody sets quiet hours from nine to five.
     */
    public function isQuietAt(CarbonImmutable $moment): bool
    {
        if (blank($this->quiet_hours_from) || blank($this->quiet_hours_to)) {
            return false;
        }

        $local = $moment->setTimezone($this->timezone);
        $minutes = $local->hour * 60 + $local->minute;

        $from = $this->minutesOfDay((string) $this->quiet_hours_from);
        $to = $this->minutesOfDay((string) $this->quiet_hours_to);

        if ($from === $to) {
            return false;
        }

        return $from < $to
            ? $minutes >= $from && $minutes < $to
            : $minutes >= $from || $minutes < $to;
    }

    /**
     * The next moment an alert may be delivered. Inside quiet hours that is
     * when they end; otherwise it is now.
     */
    public function audibleAfter(CarbonImmutable $moment): CarbonImmutable
    {
        if (! $this->isQuietAt($moment)) {
            return $moment;
        }

        $local = $moment->setTimezone($this->timezone);
        $to = $this->minutesOfDay((string) $this->quiet_hours_to);

        $end = $local->startOfDay()->addMinutes($to);

        return ($end->lessThanOrEqualTo($local) ? $end->addDay() : $end)->utc();
    }

    private function minutesOfDay(string $time): int
    {
        [$hours, $minutes] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        return $hours * 60 + $minutes;
    }

    public function heroUrl(): ?string
    {
        return $this->mediaUrl($this->hero_path);
    }

    public function logoUrl(): ?string
    {
        return $this->mediaUrl($this->logo_path);
    }

    /**
     * Images move from the public disk to object storage between development
     * and production, so nothing stores a URL — only the path (SPEC §30.3).
     */
    private function mediaUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return Storage::disk(config('media.disk'))->url($path);
    }

    /**
     * How much of the public profile is filled in, as a percentage.
     *
     * Drives the nudge in settings (SPEC §33.1). Nothing here is required to
     * take bookings — a thin profile only costs search visibility.
     *
     * @return array{percent: int, missing: list<string>}
     */
    public function profileCompleteness(): array
    {
        $checks = [
            'description' => filled($this->description),
            'address' => filled($this->address) && filled($this->city),
            'map position' => $this->latitude !== null && $this->longitude !== null,
            'phone' => filled($this->phone),
            'category' => filled($this->venue_category),
            'photos' => filled($this->hero_path) || filled($this->logo_path),
        ];

        $done = count(array_filter($checks));
        $total = count($checks);

        return [
            'percent' => (int) round($done / $total * 100),
            'missing' => array_keys(array_filter($checks, static fn (bool $ok): bool => ! $ok)),
        ];
    }

    /**
     * A page with almost nothing on it is kept out of search results on
     * purpose: thin near-duplicate pages are penalised across the whole
     * domain, which would hurt every other venue too (SPEC §33.1).
     */
    public function shouldBeIndexed(): bool
    {
        return $this->is_indexable
            && $this->profileCompleteness()['percent'] >= 60;
    }
}
