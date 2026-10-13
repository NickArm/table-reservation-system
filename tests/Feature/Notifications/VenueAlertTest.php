<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Events\ReservationWasConfirmed;
use App\Jobs\EmailVenueAlert;
use App\Mail\VenueBookingAlert;
use App\Models\Reservation;
use App\Notifications\BookingArrived;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * Telling the venue (SPEC §10.2).
 *
 * The bell is for an owner already looking at the dashboard; the email is for
 * the other twenty-three hours. Quiet hours delay the email and never drop it,
 * because a booking made at four in the morning is still a booking — and
 * waking somebody over it is what loses the account.
 */
final class VenueAlertTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    #[Test]
    public function the_venue_is_emailed_at_every_address_it_listed(): void
    {
        Mail::fake();

        $reservation = $this->booking(['notification_emails' => ['kitchen@demo.test', 'manager@demo.test']]);

        $this->fire($reservation);

        // The owner's own address is always included, whatever the settings
        // say: an account nobody can reach is an account whose bookings go
        // unnoticed.
        Mail::assertSent(VenueBookingAlert::class, 3);
        Mail::assertSent(
            VenueBookingAlert::class,
            fn (VenueBookingAlert $mail): bool => $mail->hasTo($this->owner->email)
        );
        Mail::assertSent(
            VenueBookingAlert::class,
            fn (VenueBookingAlert $mail): bool => $mail->hasTo('kitchen@demo.test')
        );
    }

    #[Test]
    public function a_reply_to_the_alert_reaches_the_guest(): void
    {
        $reservation = $this->booking();

        $this->fire($reservation);

        $alerts = array_values(array_filter(
            Mail::mailer()->getSymfonyTransport()->messages()->all(),
            static fn (object $sent): bool => str_contains(
                (string) $sent->getOriginalMessage()->getSubject(),
                'New booking'
            )
        ));

        $this->assertNotEmpty($alerts);

        $email = $alerts[0]->getOriginalMessage();

        // Hitting reply should write to the person who booked, not to us.
        $this->assertSame(
            (string) $reservation->customer_email,
            $email->getReplyTo()[0]->getAddress()
        );

        // Everything a bar needs is in the subject, because often that is as
        // far as it gets read.
        $this->assertStringContainsString('New booking', (string) $email->getSubject());
        $this->assertStringContainsString('2p', (string) $email->getSubject());
    }

    #[Test]
    public function the_bell_is_filled_for_every_member_of_the_venue(): void
    {
        $reservation = $this->booking();

        $this->fire($reservation);

        $unread = $this->owner->fresh()?->unreadNotifications ?? collect();

        $this->assertCount(1, $unread);
        $this->assertSame(BookingArrived::class, $unread->first()?->type);

        // Copied in rather than looked up, so that a later cancellation does
        // not rewrite the history of what was announced.
        $data = $unread->first()?->data ?? [];

        $this->assertSame((string) $reservation->uuid, $data['reservation_uuid']);
        $this->assertSame($reservation->customer_name, $data['guest']);
    }

    #[Test]
    public function an_alert_in_the_small_hours_waits_until_quiet_hours_end(): void
    {
        Bus::fake();

        // Half past three in Corfu, in the middle of the venue's quiet hours.
        $this->travelTo(CarbonImmutable::parse('2026-07-04 03:30', 'Europe/Athens'));

        $reservation = $this->booking([
            'quiet_hours_from' => '01:00',
            'quiet_hours_to' => '08:00',
        ]);

        $this->fire($reservation);

        Bus::assertDispatched(EmailVenueAlert::class, static function (EmailVenueAlert $job): bool {
            // Held back until the quiet hours end, not dropped.
            return $job->delay instanceof DateTimeInterface
                && CarbonImmutable::instance($job->delay)
                    ->setTimezone('Europe/Athens')
                    ->format('H:i') === '08:00';
        });
    }

    #[Test]
    public function an_alert_during_the_day_goes_out_at_once(): void
    {
        Bus::fake();

        $this->travelTo(CarbonImmutable::parse('2026-07-04 13:00', 'Europe/Athens'));

        $reservation = $this->booking([
            'quiet_hours_from' => '01:00',
            'quiet_hours_to' => '08:00',
        ]);

        $this->fire($reservation);

        Bus::assertDispatched(
            EmailVenueAlert::class,
            static fn (EmailVenueAlert $job): bool => $job->delay === null
        );
    }

    #[Test]
    public function a_venue_that_switched_the_emails_off_still_gets_the_bell(): void
    {
        Mail::fake();

        $reservation = $this->booking(['notify_on_booking' => false]);

        $this->fire($reservation);

        Mail::assertNotSent(VenueBookingAlert::class);
        $this->assertCount(1, $this->owner->fresh()?->unreadNotifications ?? collect());
    }

    /**
     * @param  array<string, mixed>  $venueSettings
     */
    private function booking(array $venueSettings = []): Reservation
    {
        $this->signInAsOwner('Demo Beach Bar');

        if ($venueSettings !== []) {
            $this->asTenant(fn () => $this->venue->fill($venueSettings)->save());
        }

        $reservation = $this->asTenant(fn (): Reservation => Reservation::factory()->create([
            'customer_name' => 'Marta Olsen',
            'customer_email' => 'marta@example.test',
            'party_size' => 2,
            'resource_snapshot' => ['label' => 'A1', 'area' => 'Front row', 'components' => null],
        ]));

        auth()->logout();
        app(TenantContext::class)->forget();

        return $reservation;
    }

    private function fire(Reservation $reservation): void
    {
        app(TenantContext::class)->forget();

        event(new ReservationWasConfirmed(
            tenantId: (int) $reservation->tenant_id,
            reservationId: (int) $reservation->getKey(),
            uuid: (string) $reservation->uuid,
        ));
    }
}
