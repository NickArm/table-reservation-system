<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\Channel;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Mail\BookingVoucher;
use App\Mail\VenueBookingAlert;
use App\Models\BookingType;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Resource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\Concerns\BuildsVenueStructure;
use Tests\TestCase;

/**
 * A booking taken over the telephone (SPEC §13.1).
 *
 * The same spot and the same guest list as an online booking; what differs is
 * who is allowed to decide what. Staff can leave out an email, set their own
 * price, and overrule the availability check — and every one of those has to
 * leave a trace rather than happen quietly.
 */
final class ManualBookingTest extends TestCase
{
    use ActsAsVenueOwner;
    use BuildsVenueStructure;
    use RefreshDatabase;

    private BookingType $bookingType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00', 'Europe/Athens'));

        $this->signInAsOwner('Demo Beach Bar');

        $this->bookingType = $this->asTenant(function (): BookingType {
            $type = $this->sunbedType(['name' => 'Sunbeds', 'deposit_label' => 'Deducted from your bill']);
            $area = $this->areaIn($type, 'Front row');
            $this->resourcesIn($area, 'A', 3, ['base_price_cents' => 3000]);

            return $type;
        });
    }

    #[Test]
    public function the_form_walks_the_same_three_steps_a_guest_does(): void
    {
        $this->get(route('dashboard.bookings.create', [
            'type' => $this->bookingType->slug,
            'date' => '2026-07-04',
            'people' => 2,
        ]))->assertOk()->assertSee('Pick a time');

        $this->get(route('dashboard.bookings.create', [
            'type' => $this->bookingType->slug,
            'date' => '2026-07-04',
            'people' => 2,
            'at' => $this->at('11:00')->toIso8601String(),
        ]))->assertOk()->assertSee('Which one')->assertSee('A1');
    }

    #[Test]
    public function a_booking_started_from_a_guests_card_arrives_filled_in(): void
    {
        $guest = $this->asTenant(fn (): Guest => Guest::factory()->create([
            'name' => 'Maria Kosta',
            'email' => 'maria@example.test',
            'phone' => '+306941234567',
        ]));

        /*
         * Typing a name, a number and an address that are already on file,
         * while the person they belong to waits on the telephone, is the work
         * this screen exists to remove. The stored number is E.164, so it is
         * split back onto the two fields the form uses.
         */
        $this->get(route('dashboard.bookings.create', [
            'type' => $this->bookingType->slug,
            'date' => '2026-07-04',
            'people' => 2,
            'at' => $this->at('11:00')->toIso8601String(),
            'guest' => $guest->id,
        ]))
            ->assertOk()
            ->assertSee('Booking for Maria Kosta')
            ->assertSee('value="Maria Kosta"', false)
            ->assertSee('value="maria@example.test"', false)
            ->assertSee('value="6941234567"', false);
    }

    #[Test]
    public function the_guest_survives_the_walk_to_the_time_list(): void
    {
        $guest = $this->asTenant(fn (): Guest => Guest::factory()->create(['name' => 'Maria Kosta']));

        // The times are links, and a link that drops the guest sends the
        // owner back to typing — which is the whole point of starting from
        // the card.
        $this->get(route('dashboard.bookings.create', [
            'type' => $this->bookingType->slug,
            'date' => '2026-07-04',
            'people' => 2,
            'guest' => $guest->id,
        ]))
            ->assertOk()
            ->assertSee('guest='.$guest->id);
    }

    #[Test]
    public function a_time_that_was_never_offered_does_not_open_the_form(): void
    {
        // Typing a timestamp into the address bar should not get a booking form
        // for four in the morning.
        $this->get(route('dashboard.bookings.create', [
            'type' => $this->bookingType->slug,
            'date' => '2026-07-04',
            'people' => 2,
            'at' => $this->at('04:00')->toIso8601String(),
        ]))->assertOk()->assertDontSee('Which one');
    }

    #[Test]
    public function a_phone_booking_lands_with_the_guest_on_the_list(): void
    {
        Mail::fake();

        $this->post(route('dashboard.bookings.store'), $this->payload([
            'email' => 'Maria@Example.test',
        ]))->assertRedirect();

        $reservation = $this->asTenant(static fn (): Reservation => Reservation::query()->firstOrFail());

        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertSame(Channel::Phone, $reservation->channel);
        $this->assertSame(3000, $reservation->amount_cents);
        $this->assertSame(PaymentStatus::PayOnArrival, $reservation->payment_status);

        // Nobody ticked a box on the telephone, and recording otherwise would
        // make the one piece of evidence that matters worthless everywhere.
        $this->assertNull($reservation->terms_accepted_at);

        // Who took it, so a question about the booking has an answer.
        $this->assertSame($this->owner->id, $reservation->created_by);

        $guest = $this->asTenant(static fn (): ?Guest => Guest::query()->first());

        $this->assertNotNull($guest);
        $this->assertSame('maria@example.test', $guest->email);
        $this->assertSame(1, $guest->bookings_count);
        $this->assertSame($guest->id, $reservation->guest_id);

        // The guest gets the same voucher as anybody else.
        Mail::assertSent(BookingVoucher::class);

        // The venue is not emailed about a booking it just typed in itself.
        Mail::assertNotSent(VenueBookingAlert::class);
    }

    #[Test]
    public function a_booking_with_no_email_still_works_and_sends_nothing(): void
    {
        Mail::fake();

        $this->post(route('dashboard.bookings.store'), $this->payload(['email' => '']))
            ->assertRedirect();

        $reservation = $this->asTenant(static fn (): Reservation => Reservation::query()->firstOrFail());

        $this->assertNull($reservation->customer_email);
        $this->assertNull($reservation->guest_id);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);

        Mail::assertNothingSent();
    }

    #[Test]
    public function the_owner_can_set_their_own_price(): void
    {
        Mail::fake();

        // A regular rang up and was given last year's rate. The owner knows
        // that and the price list does not.
        $this->post(route('dashboard.bookings.store'), $this->payload(['amount' => '12.50']))
            ->assertRedirect();

        $this->assertSame(
            1250,
            $this->asTenant(static fn (): int => (int) Reservation::query()->firstOrFail()->amount_cents)
        );
    }

    #[Test]
    public function marking_it_paid_records_the_money_as_in_hand(): void
    {
        Mail::fake();

        $this->post(route('dashboard.bookings.store'), $this->payload(['paid' => '1']))
            ->assertRedirect();

        $this->assertSame(
            PaymentStatus::Paid,
            $this->asTenant(static fn (): PaymentStatus => Reservation::query()->firstOrFail()->payment_status)
        );
    }

    #[Test]
    public function a_taken_spot_is_refused_until_the_venue_insists(): void
    {
        Mail::fake();

        $this->post(route('dashboard.bookings.store'), $this->payload())->assertRedirect();

        // The same spot at the same time. Refused, with the way out named.
        $this->post(route('dashboard.bookings.store'), $this->payload())
            ->assertSessionHasErrors('resource');

        $this->assertSame(1, $this->asTenant(static fn (): int => Reservation::query()->count()));

        // The owner is standing in the room and we are not. But the override
        // is written on the booking rather than happening quietly.
        $this->post(route('dashboard.bookings.store'), $this->payload(['force' => '1']))
            ->assertRedirect();

        $second = $this->asTenant(static fn (): Reservation => Reservation::query()->latest('id')->firstOrFail());

        $this->assertStringContainsString('Booked over availability', (string) $second->notes);
        $this->assertSame(2, $this->asTenant(static fn (): int => Reservation::query()->count()));
    }

    #[Test]
    public function a_phone_number_is_insisted_upon(): void
    {
        // The one field that is stricter than the public form: it is how the
        // venue reaches somebody who has not turned up.
        $this->post(route('dashboard.bookings.store'), $this->payload(['phone' => '']))
            ->assertSessionHasErrors('phone');

        $this->post(route('dashboard.bookings.store'), $this->payload(['phone' => '12']))
            ->assertSessionHasErrors('phone');
    }

    #[Test]
    public function the_autocomplete_offers_people_who_have_booked_before(): void
    {
        Mail::fake();

        $this->post(route('dashboard.bookings.store'), $this->payload([
            'name' => 'Maria Kosta',
            'email' => 'maria@example.test',
        ]))->assertRedirect();

        $response = $this->getJson(route('dashboard.guests.suggest', ['q' => 'kost']));

        $response->assertOk()
            ->assertJsonPath('guests.0.name', 'Maria Kosta')
            ->assertJsonPath('guests.0.email', 'maria@example.test');

        // The venue's private notes never leave the guest's own card.
        $response->assertJsonMissingPath('guests.0.internal_notes');

        // Two characters minimum, so every keystroke is not a query.
        $this->getJson(route('dashboard.guests.suggest', ['q' => 'k']))
            ->assertOk()
            ->assertJsonCount(0, 'guests');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $resource = $this->asTenant(static fn (): Resource => Resource::query()
            ->where('label', 'A1')
            ->firstOrFail());

        return [
            'resource' => $resource->id,
            'starts_at' => $this->at('11:00')->toIso8601String(),
            'people' => 2,
            'name' => 'Maria Kosta',
            'email' => 'maria@example.test',
            'calling_code' => '+30',
            'phone' => '6941234567',
            'channel' => Channel::Phone->value,
            ...$overrides,
        ];
    }

    private function at(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-07-04 '.$time, 'Europe/Athens')->utc();
    }
}
