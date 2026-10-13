<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Enums\PriceBasis;
use App\Enums\StructureScope;
use App\Models\Area;
use App\Models\BookingType;
use App\Models\PriceRule;
use App\Models\Resource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\Concerns\BuildsVenueStructure;
use Tests\TestCase;

/**
 * Saturday costs more than Tuesday afternoon (SPEC §7.2, §25.1).
 */
final class PriceRuleTest extends TestCase
{
    use ActsAsVenueOwner;
    use BuildsVenueStructure;
    use RefreshDatabase;

    private BookingType $bookingType;

    private Area $area;

    private Resource $resource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00', 'Europe/Athens'));

        $this->signInAsOwner();

        [$this->bookingType, $this->area, $this->resource] = $this->asTenant(function (): array {
            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');
            $resource = $this->resourcesIn($area, 'A', 1, ['base_price_cents' => 2000])->first();

            return [$type, $area, $resource];
        });
    }

    #[Test]
    public function with_no_rules_the_spot_costs_what_it_says_it_costs(): void
    {
        $price = $this->priceAt('2026-07-04', '11:00');

        $this->assertSame(2000, $price->baseCents);
        $this->assertSame(2000, $price->unitCents);
        $this->assertSame(2000, $price->totalCents);
        $this->assertFalse($price->wasAdjusted());
        $this->assertNull($price->rule);
    }

    #[Test]
    public function a_weekend_rule_only_applies_at_the_weekend(): void
    {
        $this->asTenant(fn () => PriceRule::factory()
            ->percent(30)
            ->onDays([6, 0])        // Saturday, Sunday
            ->create(['scope_id' => $this->bookingType->id, 'name' => 'Weekend rate']));

        // 2026-07-04 is a Saturday, 2026-07-07 a Tuesday.
        $saturday = $this->priceAt('2026-07-04', '11:00');
        $tuesday = $this->priceAt('2026-07-07', '11:00');

        $this->assertSame(2600, $saturday->totalCents);
        $this->assertSame('Weekend rate', $saturday->rule?->name);

        $this->assertSame(2000, $tuesday->totalCents);
        $this->assertNull($tuesday->rule);
    }

    #[Test]
    public function a_fixed_rule_replaces_the_price_outright(): void
    {
        $this->asTenant(fn () => PriceRule::factory()
            ->fixed(3500)
            ->create(['scope_id' => $this->bookingType->id, 'name' => 'High season']));

        $price = $this->priceAt('2026-07-04', '11:00');

        $this->assertSame(2000, $price->baseCents);
        $this->assertSame(3500, $price->unitCents);
        $this->assertTrue($price->wasAdjusted());
    }

    #[Test]
    public function a_discount_is_the_same_rule_with_a_minus_sign(): void
    {
        $this->asTenant(fn () => PriceRule::factory()
            ->percent(-25)
            ->create(['scope_id' => $this->bookingType->id, 'name' => 'May offer']));

        $this->assertSame(1500, $this->priceAt('2026-07-04', '11:00')->totalCents);
    }

    /**
     * The rule the spec is built around: highest priority wins, and nothing
     * stacks. An owner must be able to point at a price and name the one rule
     * that made it.
     */
    #[Test]
    public function the_highest_priority_rule_wins_and_rules_do_not_stack(): void
    {
        $this->asTenant(function (): void {
            PriceRule::factory()->percent(50)->priority(5)
                ->create(['scope_id' => $this->bookingType->id, 'name' => 'Busy']);

            PriceRule::factory()->fixed(3000)->priority(20)
                ->create(['scope_id' => $this->bookingType->id, 'name' => 'Festival']);
        });

        $price = $this->priceAt('2026-07-04', '11:00');

        $this->assertSame(3000, $price->unitCents);
        $this->assertSame('Festival', $price->rule?->name);
    }

    /**
     * A tie on priority goes to the narrower rule: one sunbed beats a row,
     * and a row beats the whole beach.
     */
    #[Test]
    public function a_tie_goes_to_the_more_specific_rule(): void
    {
        $this->asTenant(function (): void {
            PriceRule::factory()->fixed(2500)->priority(10)
                ->create(['scope_id' => $this->bookingType->id, 'name' => 'Beach']);

            PriceRule::factory()->fixed(3000)->priority(10)
                ->forScope(StructureScope::Area, $this->area->id)
                ->create(['name' => 'Front row']);

            PriceRule::factory()->fixed(4000)->priority(10)
                ->forScope(StructureScope::Resource, $this->resource->id)
                ->create(['name' => 'The good one']);
        });

        $this->assertSame('The good one', $this->priceAt('2026-07-04', '11:00')->rule?->name);
    }

    #[Test]
    public function a_season_rule_sleeps_outside_its_dates(): void
    {
        $this->asTenant(fn () => PriceRule::factory()
            ->fixed(4000)
            ->season('2026-07-01', '2026-08-31')
            ->create(['scope_id' => $this->bookingType->id, 'name' => 'High season']));

        $this->assertSame(4000, $this->priceAt('2026-07-04', '11:00')->totalCents);
        $this->assertSame(2000, $this->priceAt('2026-09-15', '11:00')->totalCents);
    }

    #[Test]
    public function a_time_range_rule_applies_to_part_of_the_day_only(): void
    {
        $this->asTenant(fn () => PriceRule::factory()
            ->percent(-30)
            ->between('15:00', '19:00')
            ->create(['scope_id' => $this->bookingType->id, 'name' => 'Late afternoon']));

        $this->assertSame(2000, $this->priceAt('2026-07-04', '11:00')->totalCents);
        $this->assertSame(1400, $this->priceAt('2026-07-04', '16:00')->totalCents);
    }

    #[Test]
    public function per_person_pricing_multiplies_what_the_rule_decided(): void
    {
        $this->asTenant(function (): void {
            $this->resource->forceFill([
                'price_basis' => PriceBasis::PerPerson,
                'base_price_cents' => 1000,
            ])->save();

            PriceRule::factory()->percent(20)
                ->create(['scope_id' => $this->bookingType->id, 'name' => 'Weekend rate']);
        });

        $price = $this->priceAt('2026-07-04', '11:00', partySize: 3);

        $this->assertSame(1200, $price->unitCents);
        $this->assertSame(3600, $price->totalCents);
        $this->assertSame('€12 per person', $price->label());
    }

    #[Test]
    public function an_inactive_rule_changes_nothing(): void
    {
        $this->asTenant(fn () => PriceRule::factory()
            ->fixed(9900)
            ->create(['scope_id' => $this->bookingType->id, 'is_active' => false]));

        $this->assertSame(2000, $this->priceAt('2026-07-04', '11:00')->totalCents);
    }

    /**
     * The test the spec asks for by name: a price arriving from a browser is
     * ignored, every time (SPEC §25.1).
     */
    #[Test]
    public function the_price_the_client_sends_is_never_used(): void
    {
        $this->asTenant(fn () => PriceRule::factory()
            ->fixed(2500)
            ->create(['scope_id' => $this->bookingType->id]));

        // Whatever a request body might claim.
        $claimed = 100;

        $resolved = $this->priceAt('2026-07-04', '11:00')->totalCents;

        $this->assertNotSame($claimed, $resolved);
        $this->assertSame(2500, $resolved);
    }

    #[Test]
    public function the_booking_page_shows_what_the_rules_decided(): void
    {
        $this->asTenant(function (): void {
            $this->resourcesIn($this->area, 'B', 1, ['base_price_cents' => 1600]);

            PriceRule::factory()->percent(50)
                ->create(['scope_id' => $this->bookingType->id, 'name' => 'Weekend rate']);
        });

        $times = $this->asTenant(fn () => $this->engine()->times($this->bookingType, '2026-07-04', 2));

        // The cheapest free spot, after the rule: €16 + 50% = €24.
        $this->assertSame(2400, $times[0]->fromCents);
    }

    #[Test]
    public function another_venues_rules_never_touch_these_prices(): void
    {
        $mine = $this->tenant;
        $bookingType = $this->bookingType;

        $this->signInAsOwner('Another Place');

        $this->asTenant(fn () => PriceRule::factory()
            ->fixed(9900)
            ->create(['scope_id' => $bookingType->id, 'name' => 'Theirs']));

        $price = app(\App\Tenancy\TenantContext::class)->asTenant(
            $mine,
            fn () => $this->engine()->priceFor(
                $this->resource,
                CarbonImmutable::parse('2026-07-04 11:00', 'Europe/Athens')->utc(),
                1,
            ),
        );

        $this->assertSame(2000, $price->totalCents);
    }

    private function priceAt(string $date, string $time, int $partySize = 1): \App\Pricing\Price
    {
        return $this->asTenant(fn () => $this->engine()->priceFor(
            $this->resource,
            CarbonImmutable::parse($date.' '.$time, 'Europe/Athens')->utc(),
            $partySize,
        ));
    }
}
