<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\SendDueReminders;
use App\Enums\ReservationStatus;
use App\Mail\BookingReminder;
use App\Models\Reservation;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * Reminders before a table is due (SPEC §10.1).
 *
 * The thing most worth protecting here is that nobody is reminded twice. An
 * email that arrives once is a courtesy; the same email three times is the
 * reason people mark a sender as spam, and the sender is us for every venue on
 * the platform.
 */
final class ReminderTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-04 12:00', 'Europe/Athens'));

        $this->signInAsOwner('Demo Beach Bar');

        $this->asTenant(fn () => $this->venue->fill(['reminder_hours_before' => 24])->save());

        auth()->logout();
        app(TenantContext::class)->forget();
    }

    #[Test]
    public function a_booking_inside_the_window_is_reminded_once(): void
    {
        Mail::fake();

        $reservation = $this->booking(CarbonImmutable::parse('2026-07-05 09:00', 'Europe/Athens'));

        $this->run();

        Mail::assertSent(
            BookingReminder::class,
            fn (BookingReminder $mail): bool => $mail->hasTo((string) $reservation->customer_email)
        );

        $this->assertNotNull(
            $this->asTenant(fn (): ?Reservation => Reservation::query()->find($reservation->getKey()))
                ?->reminder_sent_at
        );

        // Run again a minute later — and nothing more goes out.
        $this->travel(1)->minutes();
        $this->run();

        Mail::assertSentCount(1);
    }

    #[Test]
    public function a_booking_beyond_the_window_waits(): void
    {
        Mail::fake();

        $this->booking(CarbonImmutable::parse('2026-07-20 09:00', 'Europe/Athens'));

        $this->run();

        Mail::assertNothingSent();
    }

    #[Test]
    public function nobody_is_reminded_about_a_booking_they_just_made(): void
    {
        Mail::fake();

        $reservation = $this->booking(CarbonImmutable::parse('2026-07-04 20:00', 'Europe/Athens'));

        // Booked ten minutes ago, for tonight. They have not forgotten yet,
        // and a reminder arriving straight after a confirmation reads as a
        // system that is broken.
        $this->asTenant(fn () => $reservation->forceFill(['created_at' => now()->subMinutes(10)])->save());

        $this->run();

        Mail::assertNothingSent();
    }

    #[Test]
    public function cancelled_and_finished_bookings_are_left_alone(): void
    {
        Mail::fake();

        $startsAt = CarbonImmutable::parse('2026-07-05 09:00', 'Europe/Athens');

        $this->booking($startsAt, ['status' => ReservationStatus::CancelledByVenue]);
        $this->booking($startsAt, ['status' => ReservationStatus::NoShow]);
        $this->booking($startsAt->subDays(3), ['status' => ReservationStatus::Completed]);

        $this->run();

        Mail::assertNothingSent();
    }

    #[Test]
    public function a_venue_with_reminders_switched_off_sends_none(): void
    {
        Mail::fake();

        $this->asTenant(fn () => $this->venue->fill(['reminder_hours_before' => null])->save());

        $this->booking(CarbonImmutable::parse('2026-07-05 09:00', 'Europe/Athens'));

        $this->run();

        Mail::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function booking(CarbonImmutable $startsAt, array $attributes = []): Reservation
    {
        return $this->asTenant(function () use ($startsAt, $attributes): Reservation {
            $reservation = Reservation::factory()
                ->at($startsAt->utc(), $startsAt->addHours(2)->utc())
                ->create([
                    'resource_snapshot' => ['label' => 'A1', 'area' => 'Front row', 'components' => null],
                    ...$attributes,
                ]);

            // Booked days ago. forceFill, because created_at is deliberately
            // not fillable and strict mode refuses to discard it quietly.
            $reservation->forceFill(['created_at' => $startsAt->subDays(3)])->save();

            return $reservation;
        });
    }

    /**
     * Run it the way the scheduler does: no tenant bound, every venue at once.
     */
    private function run(): void
    {
        app(TenantContext::class)->forget();

        app(SendDueReminders::class)->handle();
    }
}
