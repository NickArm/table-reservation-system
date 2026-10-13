<?php

declare(strict_types=1);

namespace Tests\Feature\Availability;

use App\Availability\OpeningHoursResolver;
use App\Availability\Slot;
use App\Availability\SlotGenerator;
use App\Enums\BookingMode;
use App\Enums\Occupancy;
use App\Models\Area;
use App\Models\BookingType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\Concerns\BuildsVenueStructure;
use Tests\TestCase;

/**
 * The four booking modes and how long each one holds a spot (SPEC §6.2).
 */
final class SlotGenerationTest extends TestCase
{
    use ActsAsVenueOwner;
    use BuildsVenueStructure;
    use RefreshDatabase;

    private const DATE = '2026-07-04';

    private SlotGenerator $generator;

    private OpeningHoursResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAsOwner();
        $this->generator = new SlotGenerator;
        $this->resolver = new OpeningHoursResolver;
    }

    #[Test]
    public function tables_are_offered_every_half_hour_until_a_sitting_no_longer_fits(): void
    {
        $bookingType = $this->asTenant(fn () => $this->tableType());

        $labels = $this->labels($bookingType);

        $this->assertSame('19:00', $labels[0]);

        // Last sitting starts at 21:30 and ends exactly at closing.
        $this->assertSame('21:30', end($labels));
        $this->assertNotContains('22:00', $labels);
    }

    #[Test]
    public function a_table_booking_lasts_as_long_as_the_sitting(): void
    {
        $bookingType = $this->asTenant(fn () => $this->tableType());

        $slots = $this->slots($bookingType);

        $this->assertSame(90, $slots[0]->minutes());
        $this->assertSame('20:30', $slots[0]->endLabel());
    }

    #[Test]
    public function sunbeds_offer_arrival_times_inside_their_window_only(): void
    {
        $bookingType = $this->asTenant(fn () => $this->sunbedType());

        $labels = $this->labels($bookingType);

        $this->assertSame('10:00', $labels[0]);
        $this->assertSame('13:00', end($labels));
        $this->assertNotContains('09:30', $labels);
        $this->assertNotContains('13:30', $labels);
    }

    /**
     * The point of arrival windows: when you turn up is your choice, how long
     * you keep the set is the venue's rule (SPEC §6.2.1).
     */
    #[Test]
    public function a_whole_day_sunbed_is_held_until_closing_whenever_you_arrive(): void
    {
        $bookingType = $this->asTenant(fn () => $this->sunbedType());

        foreach ($this->slots($bookingType) as $slot) {
            $this->assertSame('19:00', $slot->endLabel());
        }
    }

    #[Test]
    public function an_area_can_turn_over_while_the_booking_type_does_not(): void
    {
        [$bookingType, $front, $garden] = $this->asTenant(function (): array {
            $type = $this->sunbedType();

            return [
                $type,
                $this->areaIn($type, 'Front row'),
                $this->areaIn($type, 'Garden row', [
                    'occupancy' => Occupancy::FixedDuration,
                    'max_stay_minutes' => 240,
                ]),
            ];
        });

        $frontSlots = $this->slots($bookingType, $front);
        $gardenSlots = $this->slots($bookingType, $garden);

        $this->assertSame('19:00', $frontSlots[0]->endLabel());

        // Arrive at 10:00 in the garden and the set is yours until 14:00.
        $this->assertSame('14:00', $gardenSlots[0]->endLabel());
        $this->assertSame(240, $gardenSlots[0]->minutes());
    }

    #[Test]
    public function half_days_are_two_slots_and_a_full_day_is_one(): void
    {
        $half = $this->asTenant(fn () => $this->bookingTypeOpen('09:00', '19:00', [
            'booking_mode' => BookingMode::HalfDay,
            'occupancy' => Occupancy::WholeDay,
        ]));

        $full = $this->asTenant(fn () => $this->bookingTypeOpen('09:00', '19:00', [
            'booking_mode' => BookingMode::FullDay,
            'occupancy' => Occupancy::WholeDay,
        ]));

        $halves = $this->slots($half);
        $wholes = $this->slots($full);

        $this->assertCount(2, $halves);
        $this->assertSame('Morning', $halves[0]->name);
        $this->assertSame('09:00', $halves[0]->label());
        $this->assertSame('14:00', $halves[0]->endLabel());
        $this->assertSame('Afternoon', $halves[1]->name);
        $this->assertSame('19:00', $halves[1]->endLabel());

        $this->assertCount(1, $wholes);
        $this->assertSame('All day', $wholes[0]->name);
    }

    #[Test]
    public function a_kitchen_that_closes_after_midnight_still_offers_late_sittings(): void
    {
        $bookingType = $this->asTenant(
            fn () => $this->bookingTypeOpen('20:00', '01:00', [
                'booking_mode' => BookingMode::Slot,
                'occupancy' => Occupancy::FixedDuration,
                'slot_duration_minutes' => 60,
                'max_stay_minutes' => 60,
                'slot_interval_minutes' => 60,
                'min_lead_time_minutes' => 0,
            ], closesNextDay: true)
        );

        $labels = $this->labels($bookingType);

        $this->assertSame(['20:00', '21:00', '22:00', '23:00', '00:00'], $labels);
    }

    #[Test]
    public function a_closed_day_offers_nothing(): void
    {
        $bookingType = $this->asTenant(function (): BookingType {
            $type = $this->tableType();
            $this->closeOn($type, 6);  // Saturday

            return $type;
        });

        $this->assertSame([], $this->slots($bookingType));
    }

    /**
     * @return array<int, Slot>
     */
    private function slots(BookingType $bookingType, ?Area $area = null): array
    {
        return $this->asTenant(fn (): array => $this->generator->generate(
            $bookingType,
            $this->resolver->resolve($bookingType, self::DATE, 'Europe/Athens'),
            $area,
        ));
    }

    /**
     * @return array<int, string>
     */
    private function labels(BookingType $bookingType, ?Area $area = null): array
    {
        return array_map(
            static fn (Slot $slot): string => $slot->label(),
            $this->slots($bookingType, $area),
        );
    }
}
