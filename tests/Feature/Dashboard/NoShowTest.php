<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * Nobody came (SPEC §13.1, §28.3).
 *
 * Marked from across a busy room, so it has to be both idempotent and
 * reversible — and it has to move the one number an owner will act on later.
 */
final class NoShowTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    private Reservation $reservation;

    private Guest $guest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-04 22:00', 'Europe/Athens'));

        $this->signInAsOwner('Demo Beach Bar');

        [$this->reservation, $this->guest] = $this->asTenant(function (): array {
            $guest = Guest::factory()->create(['name' => 'Yannis Late']);

            $startsAt = CarbonImmutable::parse('2026-07-04 20:00', 'Europe/Athens');

            $reservation = Reservation::factory()
                ->at($startsAt->utc(), $startsAt->addHours(2)->utc())
                ->create([
                    'customer_name' => 'Yannis Late',
                    'resource_snapshot' => ['label' => 'A1', 'area' => 'Front row', 'components' => null],
                ]);

            $reservation->forceFill(['guest_id' => $guest->id])->save();

            return [$reservation, $guest];
        });
    }

    #[Test]
    public function marking_a_no_show_moves_the_guests_count(): void
    {
        $this->patch(route('dashboard.reservations.no-show', $this->reservation))
            ->assertRedirect();

        $this->assertSame(ReservationStatus::NoShow, $this->reload()->status);
        $this->assertSame(1, $this->freshGuest()->no_show_count);

        // The flag an owner sees next to the name from then on.
        $this->assertSame('1 no-show', $this->freshGuest()->warning());
    }

    #[Test]
    public function marking_it_twice_changes_nothing(): void
    {
        // Idempotent for the same reason check-in is: a tired thumb, a double
        // tap, an offline scanner replaying its queue (SPEC §32.3).
        $this->patch(route('dashboard.reservations.no-show', $this->reservation));
        $this->patch(route('dashboard.reservations.no-show', $this->reservation));

        $this->assertSame(1, $this->freshGuest()->no_show_count);
    }

    #[Test]
    public function they_can_be_put_back_if_they_walk_in(): void
    {
        $this->patch(route('dashboard.reservations.no-show', $this->reservation));
        $this->patch(route('dashboard.reservations.came-anyway', $this->reservation))
            ->assertRedirect();

        // Their table was in the past, so the booking is simply finished.
        $this->assertSame(ReservationStatus::Completed, $this->reload()->status);
        $this->assertSame(0, $this->freshGuest()->no_show_count);
    }

    #[Test]
    public function checking_somebody_in_clears_a_no_show(): void
    {
        $this->patch(route('dashboard.reservations.no-show', $this->reservation));
        $this->patch(route('dashboard.reservations.check-in', $this->reservation));

        $this->assertSame(ReservationStatus::CheckedIn, $this->reload()->status);
        $this->assertSame(0, $this->freshGuest()->no_show_count);
    }

    #[Test]
    public function the_card_offers_the_control_only_once_the_table_is_due(): void
    {
        $this->get(route('dashboard.reservations.show', $this->reservation))
            ->assertOk()
            ->assertSee('Nobody came');

        // A booking still in the future cannot be a no-show yet.
        $future = $this->asTenant(function (): Reservation {
            $startsAt = CarbonImmutable::parse('2026-07-06 20:00', 'Europe/Athens');

            return Reservation::factory()
                ->at($startsAt->utc(), $startsAt->addHours(2)->utc())
                ->create(['resource_snapshot' => ['label' => 'A2', 'area' => null, 'components' => null]]);
        });

        $this->get(route('dashboard.reservations.show', $future))
            ->assertOk()
            ->assertDontSee('Nobody came');
    }

    private function reload(): Reservation
    {
        return $this->asTenant(fn (): Reservation => Reservation::query()->findOrFail($this->reservation->id));
    }

    private function freshGuest(): Guest
    {
        return $this->asTenant(fn (): Guest => Guest::query()->findOrFail($this->guest->id));
    }
}
