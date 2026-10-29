<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\Guest;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * One booking, read with a telephone in hand (SPEC §13.1, §24.5).
 *
 * The screen is opened mid-service, so what it ranks matters more than what it
 * contains: the note the kitchen needs, a number that can be pressed, and the
 * two buttons staff reach for. Everything that moves money is a fold down.
 */
final class ReservationCardTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    private Reservation $reservation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-04 18:00', 'Europe/Athens'));

        $this->signInAsOwner('Demo Beach Bar');

        $this->reservation = $this->asTenant(function (): Reservation {
            $guest = Guest::factory()->create(['name' => 'Maria Pappas']);

            $startsAt = CarbonImmutable::parse('2026-07-04 20:30', 'Europe/Athens');

            $reservation = Reservation::factory()
                ->at($startsAt->utc(), $startsAt->addHours(2)->utc())
                ->create([
                    'customer_name' => 'Maria Pappas',
                    'customer_phone' => '+306912345678',
                    'customer_email' => 'maria@example.test',
                    'party_size' => 4,
                    'notes' => 'A high chair for the little one',
                    'resource_snapshot' => ['label' => 'A1', 'area' => 'Front row', 'components' => null],
                ]);

            $reservation->forceFill(['guest_id' => $guest->id])->save();

            return $reservation;
        });
    }

    #[Test]
    public function the_note_is_above_the_details_rather_than_among_them(): void
    {
        /*
         * A high chair nobody read is the whole failure this screen exists to
         * prevent, so the note sits between the name and the facts — not as
         * the seventh row of a list.
         */
        $this->get(route('dashboard.reservations.show', $this->reservation))
            ->assertOk()
            ->assertSeeInOrder([
                'Maria Pappas',
                'A high chair for the little one',
                'Front row',
            ]);
    }

    #[Test]
    public function the_phone_number_is_something_to_press(): void
    {
        // Mid-service nobody copies a number out of a definition list.
        $this->get(route('dashboard.reservations.show', $this->reservation))
            ->assertOk()
            ->assertSee('href="tel:+306912345678"', false)
            ->assertSee('href="mailto:maria@example.test"', false);
    }

    #[Test]
    public function the_two_buttons_staff_reach_for_sit_with_the_name(): void
    {
        $response = $this->get(route('dashboard.reservations.show', $this->reservation))
            ->assertOk();

        // Both before the first card: the header carries them, so a thumb
        // finds them without scrolling a phone.
        $response->assertSeeInOrder(['Check in', 'Reaching them']);
    }

    #[Test]
    public function cancelling_is_behind_a_fold(): void
    {
        /*
         * A form with a refund in it, permanently open on a page people come
         * to read, is a control that eventually gets used by accident.
         */
        $this->get(route('dashboard.reservations.show', $this->reservation))
            ->assertOk()
            ->assertSee('<summary>Cancel this booking</summary>', false)
            ->assertSee(route('dashboard.reservations.cancel', $this->reservation));
    }

    #[Test]
    public function an_unsent_voucher_is_stated_rather_than_left_blank(): void
    {
        // Nothing sent is a fact about the booking, not an empty row.
        $this->get(route('dashboard.reservations.show', $this->reservation))
            ->assertOk()
            ->assertSee('Not sent')
            ->assertSee('Open what the guest sees');
    }

    #[Test]
    public function a_booking_without_a_phone_or_an_email_says_so(): void
    {
        $this->asTenant(fn () => $this->reservation->forceFill([
            'customer_phone' => null,
            'customer_email' => null,
        ])->save());

        $this->get(route('dashboard.reservations.show', $this->reservation))
            ->assertOk()
            ->assertSee('No way to reach this guest', false);
    }
}
