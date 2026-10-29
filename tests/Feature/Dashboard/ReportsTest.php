<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\Channel;
use App\Enums\Gateway;
use App\Enums\GatewayMode;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\Payment;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\Concerns\BuildsVenueStructure;
use Tests\TestCase;

/**
 * How the season went (SPEC §36).
 *
 * The October screen. Every number on it is one somebody will act on — a rota,
 * a renewal, a decision about reminders — so each of these checks that the
 * figure is the honest one rather than the flattering one.
 */
final class ReportsTest extends TestCase
{
    use ActsAsVenueOwner;
    use BuildsVenueStructure;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-08-15 12:00', 'Europe/Athens'));

        $this->signInAsOwner('Demo Beach Bar');
    }

    #[Test]
    public function money_counts_what_arrived_rather_than_what_was_asked_for(): void
    {
        /*
         * An amount on a reservation is a price. A payment is money. After a
         * refund the two stop agreeing, which is exactly when an owner opens
         * this screen — so the headline is taken less refunded.
         */
        $this->asTenant(function (): void {
            $this->paidBooking(10_000);
            $this->paidBooking(5_000, refunded: 2_000);
        });

        $this->get(route('dashboard.reports', ['period' => 'season']))
            ->assertOk()
            ->assertSee('€130')
            ->assertSee('after €20 refunded');
    }

    #[Test]
    public function money_taken_with_test_keys_is_left_out(): void
    {
        /*
         * A venue that spent an evening trying its own checkout must not find
         * the season inflated by pretend money — and by looking at the figure
         * there is no way to tell.
         */
        $this->asTenant(function (): void {
            $this->paidBooking(10_000);
            $this->paidBooking(90_000, mode: GatewayMode::Test);
        });

        $this->get(route('dashboard.reports', ['period' => 'season']))
            ->assertOk()
            ->assertSee('€100')
            ->assertDontSee('€900');
    }

    #[Test]
    public function money_owed_at_the_table_is_not_counted_as_taken(): void
    {
        // A report that adds what the till has to what the till is owed is a
        // wish, not a report.
        $this->asTenant(function (): void {
            $this->paidBooking(10_000);

            $this->booking(4_000)->forceFill([
                'payment_status' => PaymentStatus::PayOnArrival,
            ])->save();
        });

        $this->get(route('dashboard.reports', ['period' => 'season']))
            ->assertOk()
            ->assertSee('€100')
            ->assertSee('€40 more was due at the venue');
    }

    #[Test]
    public function the_channel_split_is_the_number_that_decides_a_renewal(): void
    {
        $this->asTenant(function (): void {
            $this->booking(0, channel: Channel::Widget);
            $this->booking(0, channel: Channel::Widget);
            $this->booking(0, channel: Channel::Widget);
            $this->booking(0, channel: Channel::Phone);
        });

        $this->get(route('dashboard.reports', ['period' => 'season']))
            ->assertOk()
            ->assertSeeInOrder(['Website widget', '75%'])
            ->assertSee('Phone');
    }

    #[Test]
    public function no_shows_are_reported_with_what_they_cost(): void
    {
        $this->asTenant(function (): void {
            $this->paidBooking(10_000);

            $this->booking(6_000)->forceFill(['status' => ReservationStatus::NoShow])->save();
        });

        $this->get(route('dashboard.reports', ['period' => 'season']))
            ->assertOk()
            ->assertSee('1 of 2 (50%)')
            ->assertSee('€60 of booked value');
    }

    #[Test]
    public function cancelled_bookings_are_left_out_of_everything_but_their_own_count(): void
    {
        /*
         * An owner asking how full August was does not mean "including the
         * forty that cancelled" — but is still owed the number.
         */
        $this->asTenant(function (): void {
            $this->booking(0);

            $this->booking(9_000)->forceFill([
                'status' => ReservationStatus::CancelledByVenue,
            ])->save();
        });

        $this->get(route('dashboard.reports', ['period' => 'season']))
            ->assertOk()
            ->assertSee('1 cancelled on top')
            // One booking counted, not two.
            ->assertSeeInOrder(['1', 'booking']);
    }

    #[Test]
    public function a_period_with_nothing_in_it_says_so_instead_of_showing_zeroes(): void
    {
        $this->get(route('dashboard.reports', ['period' => 'last-season']))
            ->assertOk()
            ->assertSee('Nothing booked in this period.');
    }

    #[Test]
    public function a_stretch_of_dates_can_be_asked_for_by_hand(): void
    {
        $this->asTenant(fn () => $this->booking(0, at: CarbonImmutable::parse('2026-06-10 20:00', 'Europe/Athens')));

        // Inclusive at both ends: somebody asking for the 10th means all of it.
        $this->get(route('dashboard.reports', ['from' => '2026-06-10', 'to' => '2026-06-10']))
            ->assertOk()
            ->assertSee('10 Jun 2026')
            ->assertDontSee('Nothing booked in this period.');

        $this->get(route('dashboard.reports', ['from' => '2026-06-11', 'to' => '2026-06-12']))
            ->assertOk()
            ->assertSee('Nothing booked in this period.');
    }

    #[Test]
    public function a_nonsense_range_falls_back_rather_than_breaking(): void
    {
        $this->get(route('dashboard.reports', ['from' => 'yesterday-ish', 'to' => 'soon']))
            ->assertOk();
    }

    #[Test]
    public function another_venue_sees_its_own_season(): void
    {
        $this->asTenant(fn () => $this->paidBooking(10_000));

        $this->signInAsOwner('Another Place');

        $this->get(route('dashboard.reports', ['period' => 'season']))
            ->assertOk()
            ->assertSee('Nothing booked in this period.')
            ->assertDontSee('€100');
    }

    /**
     * A booking inside the current season, on a spot this venue owns.
     */
    private function booking(
        int $amountCents,
        ?Channel $channel = null,
        ?CarbonImmutable $at = null,
    ): Reservation {
        $startsAt = $at ?? CarbonImmutable::parse('2026-07-04 20:00', 'Europe/Athens');

        $reservation = Reservation::factory()
            ->at($startsAt->utc(), $startsAt->addHours(2)->utc())
            ->create([
                'amount_cents' => $amountCents,
                'channel' => $channel ?? Channel::Website,
                'resource_snapshot' => ['label' => 'A1', 'area' => 'Front row', 'components' => null],
            ]);

        return $reservation->refresh();
    }

    private function paidBooking(
        int $amountCents,
        int $refunded = 0,
        ?GatewayMode $mode = null,
    ): Reservation {
        $reservation = $this->booking($amountCents);

        $payment = new Payment;

        $payment->fill([
            'gateway' => Gateway::Stripe,
            'mode' => $mode ?? GatewayMode::Live,
            'amount_cents' => $amountCents,
            'currency' => 'EUR',
            'status' => $refunded > 0 ? PaymentStatus::PartiallyRefunded : PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        $payment->reservation_id = $reservation->id;
        $payment->refunded_amount_cents = $refunded;
        $payment->save();

        return $reservation;
    }
}
