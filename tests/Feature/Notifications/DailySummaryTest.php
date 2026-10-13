<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\SendDailySummaries;
use App\Mail\VenueDailySummary;
use App\Models\Reservation;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * The morning list (SPEC §10.2).
 *
 * The command runs every hour and picks the venues whose chosen hour it is
 * locally. That is what makes one schedule serve Corfu and the Canaries, and
 * what keeps it correct through the clock change — so the hour comparison is
 * what these tests are really about.
 */
final class DailySummaryTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    #[Test]
    public function the_list_arrives_at_the_hour_the_venue_asked_for(): void
    {
        Mail::fake();

        // Eight in the morning, Corfu time — which is five in the morning UTC.
        // A command comparing UTC hours would send this one three hours early.
        $this->travelTo(CarbonImmutable::parse('2026-07-04 08:10', 'Europe/Athens'));

        $this->venueWith(8);
        $this->bookingAt('2026-07-04 20:00');

        $this->run();

        Mail::assertSent(
            VenueDailySummary::class,
            fn (VenueDailySummary $mail): bool => $mail->total === 1
                && $mail->hasTo($this->owner->email)
        );
    }

    #[Test]
    public function nothing_is_sent_at_any_other_hour(): void
    {
        Mail::fake();

        $this->travelTo(CarbonImmutable::parse('2026-07-04 11:10', 'Europe/Athens'));

        $this->venueWith(8);
        $this->bookingAt('2026-07-04 20:00');

        $this->run();

        Mail::assertNothingSent();
    }

    #[Test]
    public function an_empty_day_is_still_reported(): void
    {
        Mail::fake();

        $this->travelTo(CarbonImmutable::parse('2026-07-04 08:10', 'Europe/Athens'));

        $this->venueWith(8);

        $this->run();

        // Silence is indistinguishable from a system that has stopped working.
        Mail::assertSent(
            VenueDailySummary::class,
            static fn (VenueDailySummary $mail): bool => $mail->total === 0
        );
    }

    #[Test]
    public function only_today_is_counted_in_the_venues_own_day(): void
    {
        Mail::fake();

        $this->travelTo(CarbonImmutable::parse('2026-07-04 08:10', 'Europe/Athens'));

        $this->venueWith(8);

        // Tonight at eight: today, and counted.
        $this->bookingAt('2026-07-04 20:00');

        // Half past midnight tomorrow. In Corfu that is tomorrow's business;
        // in UTC it is still today, so a window built on UTC days would put it
        // in this morning's list by mistake.
        $this->bookingAt('2026-07-05 00:30');

        // Last night: gone.
        $this->bookingAt('2026-07-03 21:00');

        $this->run();

        Mail::assertSent(
            VenueDailySummary::class,
            static fn (VenueDailySummary $mail): bool => $mail->total === 1
        );
    }

    #[Test]
    public function the_list_counts_people_as_well_as_bookings(): void
    {
        Mail::fake();

        $this->travelTo(CarbonImmutable::parse('2026-07-04 08:10', 'Europe/Athens'));

        $this->venueWith(8);
        $this->bookingAt('2026-07-04 20:00', 2);
        $this->bookingAt('2026-07-04 20:30', 4);

        $this->run();

        Mail::assertSent(
            VenueDailySummary::class,
            static fn (VenueDailySummary $mail): bool => $mail->total === 2 && $mail->covers === 6
        );
    }

    #[Test]
    public function a_venue_that_switched_it_off_hears_nothing(): void
    {
        Mail::fake();

        $this->travelTo(CarbonImmutable::parse('2026-07-04 08:10', 'Europe/Athens'));

        $this->venueWith(null);
        $this->bookingAt('2026-07-04 20:00');

        $this->run();

        Mail::assertNothingSent();
    }

    private function venueWith(?int $hour): void
    {
        $this->signInAsOwner('Demo Beach Bar');

        $this->asTenant(fn () => $this->venue->fill([
            'daily_summary_hour' => $hour,
            'reminder_hours_before' => null,
        ])->save());

        auth()->logout();
        app(TenantContext::class)->forget();
    }

    private function bookingAt(string $local, int $partySize = 2): Reservation
    {
        $startsAt = CarbonImmutable::parse($local, 'Europe/Athens');

        return $this->asTenant(fn (): Reservation => Reservation::factory()
            ->at($startsAt->utc(), $startsAt->addHours(2)->utc())
            ->create([
                'party_size' => $partySize,
                'resource_snapshot' => ['label' => 'A1', 'area' => 'Front row', 'components' => null],
            ]));
    }

    private function run(): void
    {
        app(TenantContext::class)->forget();

        app(SendDailySummaries::class)->handle();
    }
}
