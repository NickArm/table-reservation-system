<?php

declare(strict_types=1);

namespace Tests\Feature\Guests;

use App\Actions\Guests\AnonymizeGuest;
use App\Actions\Guests\RecordGuest;
use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\Reservation;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * The venue's own guest list (SPEC §28).
 *
 * The thing under test is the unique key: `(venue_id, email)`, never
 * `(tenant_id, email)` and never anything platform-wide. Get that wrong and
 * one venue starts seeing another's relationship with a person — including the
 * private notes they wrote about them.
 */
final class GuestRegistryTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    #[Test]
    public function booking_twice_with_the_same_address_is_one_guest(): void
    {
        $this->signInAsOwner('Demo Beach Bar');

        $this->asTenant(function (): void {
            $this->book('Nick A.', 'nick@example.test');
            $this->book('Nick Armenis', 'Nick@Example.test ', '+306941234567');

            $guests = Guest::query()->get();

            $this->assertCount(1, $guests, 'A trailing space and a capital made a second person.');

            $guest = $guests->first();

            // The latest wins: people change phones, and spell their own names
            // differently depending on the day.
            $this->assertSame('Nick Armenis', $guest->name);
            $this->assertSame('+306941234567', $guest->phone);
            $this->assertSame(2, $guest->bookings_count);
        });
    }

    #[Test]
    public function the_same_person_at_two_venues_is_two_separate_records(): void
    {
        // The rule the whole design rests on (SPEC §28.1): two venues never
        // share a guest list, not even inside one account, and neither can see
        // that the person went to the other.
        $this->signInAsOwner('Beach Bar A');
        $first = $this->tenant;

        $this->asTenant(fn () => $this->book('Nick', 'nick@example.test'));

        $this->signInAsOwner('Restaurant B');
        $second = $this->tenant;

        $this->asTenant(fn () => $this->book('Nick', 'nick@example.test'));

        $context = app(TenantContext::class);

        $a = $context->asTenant($first, fn () => Guest::query()->get());
        $b = $context->asTenant($second, fn () => Guest::query()->get());

        $this->assertCount(1, $a);
        $this->assertCount(1, $b);
        $this->assertNotSame($a->first()->id, $b->first()->id);

        // And one venue's private note never reaches the other.
        $context->asTenant($first, fn () => $a->first()->fill(['internal_notes' => 'Prefers the front row.'])->save());

        $this->assertNull($context->asTenant($second, fn () => Guest::query()->first()?->internal_notes));
    }

    #[Test]
    public function a_booking_without_an_address_has_no_guest_record(): void
    {
        $this->signInAsOwner('Demo Beach Bar');

        $this->asTenant(function (): void {
            $reservation = $this->book('Walk-in', null, '+306941234567');

            // Nothing to match it to next time, so nothing is written down.
            // Better than a row that cannot ever be recognised again.
            $this->assertNull($reservation->guest_id);
            $this->assertSame(0, Guest::query()->count());
        });
    }

    #[Test]
    public function the_counters_follow_the_reservations(): void
    {
        $this->signInAsOwner('Demo Beach Bar');

        $this->asTenant(function (): void {
            $first = $this->book('Nick', 'nick@example.test');
            $second = $this->book('Nick', 'nick@example.test');
            $third = $this->book('Nick', 'nick@example.test');

            $first->forceFill(['status' => ReservationStatus::Completed])->save();
            $second->forceFill(['status' => ReservationStatus::NoShow])->save();
            $third->forceFill(['status' => ReservationStatus::CancelledByVenue])->save();

            $guest = Guest::query()->firstOrFail();

            $this->assertSame(3, $guest->bookings_count);
            $this->assertSame(1, $guest->completed_count);
            $this->assertSame(1, $guest->no_show_count);
            $this->assertSame(1, $guest->cancelled_count);

            // A cancelled booking is not money the guest brought in.
            $this->assertSame(4000, $guest->total_spent_cents);

            $this->assertSame('1 no-show', $guest->warning());
            $this->assertSame('3rd visit', $guest->visitLabel());
        });
    }

    #[Test]
    public function the_recount_command_repairs_counters_that_drifted(): void
    {
        $this->signInAsOwner('Demo Beach Bar');

        $guest = $this->asTenant(function (): Guest {
            $this->book('Nick', 'nick@example.test');
            $this->book('Nick', 'nick@example.test');

            $guest = Guest::query()->firstOrFail();

            // Whatever broke them — a bad deploy, a console script, a bug in
            // the observer — the numbers can always be put right from the
            // reservations themselves (SPEC §28.2).
            $guest->forceFill(['bookings_count' => 99, 'no_show_count' => 7])->save();

            return $guest;
        });

        $this->artisan('guests:recount')->assertSuccessful();

        $this->asTenant(function () use ($guest): void {
            $fresh = Guest::query()->findOrFail($guest->id);

            $this->assertSame(2, $fresh->bookings_count);
            $this->assertSame(0, $fresh->no_show_count);
        });
    }

    #[Test]
    public function erasing_a_guest_keeps_the_bookings_and_loses_the_person(): void
    {
        $this->signInAsOwner('Demo Beach Bar');

        $this->asTenant(function (): void {
            $reservation = $this->book('Nick', 'nick@example.test', '+306941234567');
            $reservation->forceFill(['notes' => 'Wheelchair access please.'])->save();

            $guest = Guest::query()->firstOrFail();
            $guest->fill(['internal_notes' => 'Nice chap.', 'tags' => ['regular']])->save();

            app(AnonymizeGuest::class)->handle($guest);

            $guest->refresh();

            $this->assertSame('Deleted guest', $guest->name);
            $this->assertNull($guest->email);
            $this->assertNull($guest->phone);
            $this->assertNull($guest->internal_notes);
            $this->assertNotNull($guest->anonymized_at);

            // The venue keeps its figures for the season; the person is gone.
            $reservation->refresh();

            $this->assertSame('Deleted guest', $reservation->customer_name);
            $this->assertNull($reservation->customer_email);
            $this->assertNull($reservation->notes);
            $this->assertSame(2000, $reservation->amount_cents);
            $this->assertNotNull($reservation->starts_at);
        });
    }

    #[Test]
    public function an_erased_address_can_book_again_as_a_new_person(): void
    {
        $this->signInAsOwner('Demo Beach Bar');

        $this->asTenant(function (): void {
            $this->book('Nick', 'nick@example.test');

            app(AnonymizeGuest::class)->handle(Guest::query()->firstOrFail());

            // Clearing the email releases the unique key, so the venue can take
            // a booking from that address again. The old link stays severed —
            // that is what erasure means.
            $this->book('Nick', 'nick@example.test');

            $this->assertSame(2, Guest::query()->count());
            $this->assertSame(1, Guest::query()->whereNotNull('email')->count());
        });
    }

    /**
     * Built the way the real actions do it: the guest is matched before the
     * reservation is written, so the two land together or not at all.
     */
    private function book(string $name, ?string $email, ?string $phone = null): Reservation
    {
        $reservation = Reservation::factory()->make([
            'customer_name' => $name,
            'customer_email' => $email,
            'customer_phone' => $phone,
            'amount_cents' => 2000,
        ]);

        app(RecordGuest::class)->handle($reservation);

        $reservation->save();

        return $reservation;
    }
}
