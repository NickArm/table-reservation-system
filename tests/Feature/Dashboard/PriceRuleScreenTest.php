<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\PriceRuleType;
use App\Enums\StructureScope;
use App\Models\Area;
use App\Models\BookingType;
use App\Models\PriceRule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\Concerns\BuildsVenueStructure;
use Tests\TestCase;

/**
 * Price rules from the dashboard — and the promise that a venue with one
 * price never has to meet them (SPEC §1.1, §7.2).
 */
final class PriceRuleScreenTest extends TestCase
{
    use ActsAsVenueOwner;
    use BuildsVenueStructure;
    use RefreshDatabase;

    private BookingType $bookingType;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00', 'Europe/Athens'));

        $this->signInAsOwner();

        [$this->bookingType, $this->area] = $this->asTenant(function (): array {
            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');
            $this->resourcesIn($area, 'A', 2, ['base_price_cents' => 2000]);

            return [$type, $area];
        });
    }

    #[Test]
    public function pricing_stays_out_of_the_way_until_somebody_asks_for_it(): void
    {
        $this->get(route('dashboard.booking-types.show', $this->bookingType))
            ->assertOk()
            ->assertSee('Charge a different price at weekends or in season')
            ->assertDontSee('priority 10');
    }

    #[Test]
    public function a_weekend_rule_is_added_from_the_setup_screen(): void
    {
        $this->post(route('dashboard.price-rules.store'), [
            'target' => 'booking_type:'.$this->bookingType->id,
            'name' => 'Weekend rate',
            'price_type' => PriceRuleType::PercentModifier->value,
            'percent' => 30,
            'days_of_week' => [6, 0],
        ])->assertRedirect();

        $rule = $this->asTenant(static fn (): ?PriceRule => PriceRule::query()->first());

        $this->assertNotNull($rule);
        $this->assertSame('Weekend rate', $rule->name);
        $this->assertSame(30, $rule->percent);
        $this->assertSame([6, 0], $rule->days_of_week);
        $this->assertSame(StructureScope::BookingType, $rule->scope);

        $this->get(route('dashboard.booking-types.show', $this->bookingType))
            ->assertSee('Weekend rate')
            ->assertSee('Sat, Sun');
    }

    #[Test]
    public function a_fixed_price_is_stored_in_cents(): void
    {
        $this->post(route('dashboard.price-rules.store'), [
            'target' => 'area:'.$this->area->id,
            'name' => 'High season',
            'price_type' => PriceRuleType::Fixed->value,
            'amount' => '39.50',
            'date_from' => '2026-07-01',
            'date_to' => '2026-08-31',
            'priority' => 20,
        ])->assertRedirect();

        $rule = $this->asTenant(static fn (): PriceRule => PriceRule::query()->firstOrFail());

        $this->assertSame(3950, $rule->amount_cents);
        $this->assertNull($rule->percent);
        $this->assertSame(20, $rule->priority);
        $this->assertSame(StructureScope::Area, $rule->scope);
    }

    #[Test]
    public function the_rule_has_to_say_what_it_does(): void
    {
        $this->post(route('dashboard.price-rules.store'), [
            'target' => 'booking_type:'.$this->bookingType->id,
            'price_type' => PriceRuleType::Fixed->value,
        ])->assertSessionHasErrors('amount');

        $this->post(route('dashboard.price-rules.store'), [
            'target' => 'booking_type:'.$this->bookingType->id,
            'price_type' => PriceRuleType::PercentModifier->value,
        ])->assertSessionHasErrors('percent');

        $this->assertSame(0, $this->asTenant(static fn (): int => PriceRule::query()->count()));
    }

    #[Test]
    public function removing_a_rule_puts_the_usual_price_back(): void
    {
        $this->post(route('dashboard.price-rules.store'), [
            'target' => 'booking_type:'.$this->bookingType->id,
            'price_type' => PriceRuleType::Fixed->value,
            'amount' => '35',
        ]);

        $rule = $this->asTenant(static fn (): PriceRule => PriceRule::query()->firstOrFail());

        $this->delete(route('dashboard.price-rules.destroy', $rule))->assertRedirect();

        $this->assertSame(0, $this->asTenant(static fn (): int => PriceRule::query()->count()));
    }

    #[Test]
    public function another_venue_cannot_price_this_ones_sunbeds(): void
    {
        $mine = $this->bookingType;

        $this->signInAsOwner('Another Place');

        $this->post(route('dashboard.price-rules.store'), [
            'target' => 'booking_type:'.$mine->id,
            'price_type' => PriceRuleType::Fixed->value,
            'amount' => '1',
        ])->assertNotFound();

        $this->assertSame(0, $this->asTenant(static fn (): int => PriceRule::query()->count()));
    }
}
