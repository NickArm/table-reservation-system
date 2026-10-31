<?php

declare(strict_types=1);

namespace Tests\Feature\Structure;

use App\Enums\OnlineStatus;
use App\Enums\PaymentRequirement;
use App\Models\Area;
use App\Models\BookingType;
use App\Models\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * One area's card, after the redesign (SPEC §5.1, §1.1).
 *
 * The first test here is the important one, and it is not about looks. A
 * redesign may move a control or rename it; it may never quietly drop one.
 * Nobody reports a feature they cannot find, so a lost control is never
 * noticed and never fixed — this walks the inventory of the old card and
 * fails if any route to it has gone.
 */
final class AreaCardTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    private BookingType $bookingType;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAsOwner();

        [$this->bookingType, $this->area] = $this->asTenant(static function (): array {
            $bookingType = BookingType::factory()->sunbeds()->create(['name' => 'Sunbeds']);
            $area = Area::factory()->create([
                'booking_type_id' => $bookingType->id,
                'name' => 'Front row',
            ]);

            foreach (range(1, 6) as $number) {
                Resource::factory()->create([
                    'booking_type_id' => $bookingType->id,
                    'area_id' => $area->id,
                    'label' => 'A'.$number,
                    'sort_order' => $number,
                    'capacity_max' => 2,
                ]);
            }

            return [$bookingType, $area];
        });
    }

    #[Test]
    public function every_control_the_old_card_had_still_has_a_route_to_it(): void
    {
        $response = $this->get(route('dashboard.booking-types.show', $this->bookingType))->assertOk();

        // Offered on the card itself.
        foreach (['Rename', 'Seats &amp; timing', 'Open or close several', 'Remove sunbed sets'] as $label) {
            $response->assertSee($label, false);
        }

        // And each one actually opens its form, rather than being a label
        // pointing at a sheet nobody wrote.
        $opens = [
            'rename-' => 'name="description"',
            'timing-' => 'name="turnover_buffer_minutes"',
            'bulk-' => 'name="open_first"',
            'remove-' => 'Remove this permanently?',
            'spots-' => 'name="prefix"',
        ];

        foreach ($opens as $key => $marker) {
            $this->get(route('dashboard.booking-types.show', [
                $this->bookingType,
                'add' => $key.$this->area->id,
            ]))
                ->assertOk()
                ->assertSee($marker, false);
        }
    }

    #[Test]
    public function renaming_an_area_is_possible_at_all(): void
    {
        /*
         * It was not. The only form that wrote a name passed it as a hidden
         * field to preserve it while something else was saved, so an area
         * typed wrong on the first day stayed wrong — and the name is what
         * guests read when they pick a spot.
         */
        $this->patch(route('dashboard.areas.update', $this->area), [
            'name' => 'Second row',
            'description' => 'More shade',
        ])->assertRedirect();

        $this->assertSame('Second row', $this->area->fresh()->name);
    }

    #[Test]
    public function renaming_does_not_quietly_reset_the_timings(): void
    {
        // Two forms write to one endpoint. Each has to carry the other's
        // fields or saving a name silently clears the area's own timing.
        $this->asTenant(fn () => $this->area->forceFill([
            'turnover_buffer_minutes' => 20,
            'max_stay_minutes' => 120,
        ])->save());

        $this->get(route('dashboard.booking-types.show', [
            $this->bookingType,
            'add' => 'rename-'.$this->area->id,
        ]))
            ->assertOk()
            ->assertSee('name="turnover_buffer_minutes" value="20"', false)
            ->assertSee('name="max_stay_minutes" value="120"', false);
    }

    #[Test]
    public function a_tile_says_how_many_it_seats_rather_than_what_it_costs(): void
    {
        /*
         * The price is the same for every spot in an area unless a rule says
         * otherwise, so printing it on each tile taught nothing — and printed
         * "€0" twenty times for a venue that takes no deposit, which reads as
         * a fault rather than as a setting.
         */
        $this->get(route('dashboard.booking-types.show', $this->bookingType))
            ->assertOk()
            ->assertSee('seats 2, taking bookings', false)
            ->assertSee('6 sunbed sets, seats 12');
    }

    #[Test]
    public function adding_is_offered_among_the_spots_as_well_as_in_the_head(): void
    {
        // The tile that adds a tile: the control where the things are.
        $this->get(route('dashboard.booking-types.show', $this->bookingType))
            ->assertOk()
            ->assertSee('tile--add', false)
            ->assertSee('Add sunbed sets to Front row');
    }

    #[Test]
    public function a_price_is_not_stated_where_no_money_is_taken(): void
    {
        /*
         * "€0 each · No payment" side by side reads as a fault rather than as
         * a choice — the same noise the tiles used to carry. A venue that
         * takes nothing up front should be told so once.
         */
        $this->asTenant(fn () => $this->bookingType->forceFill([
            'payment_requirement' => PaymentRequirement::None,
            'default_price_cents' => 0,
        ])->save());

        $this->get(route('dashboard.booking-types.show', $this->bookingType))
            ->assertOk()
            ->assertSee('Pay at the venue')
            ->assertSee('Nothing is taken up front')
            ->assertDontSee('€0');
    }

    #[Test]
    public function a_closed_spot_can_be_told_from_an_open_one(): void
    {
        /*
         * On a venue with everything open there is no way to learn what a
         * closed spot looks like, so the one state an owner needs to read at
         * a glance is the one they never see until it matters.
         */
        $this->asTenant(fn () => Resource::query()
            ->where('label', 'A3')
            ->firstOrFail()
            ->forceFill(['online_status' => OnlineStatus::Closed])
            ->save());

        $this->get(route('dashboard.booking-types.show', $this->bookingType))
            ->assertOk()
            ->assertSee('tile--closed', false)
            ->assertSee('closed — press to open it', false)
            ->assertSee('1 closed — tap to open again');
    }
}
