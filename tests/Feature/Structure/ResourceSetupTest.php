<?php

declare(strict_types=1);

namespace Tests\Feature\Structure;

use App\Actions\Structure\BulkCreateResources;
use App\Enums\PriceBasis;
use App\Models\Area;
use App\Models\BookingType;
use App\Models\Resource;
use App\Models\ResourceTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

final class ResourceSetupTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    private BookingType $bookingType;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAsOwner();

        [$this->bookingType, $this->area] = $this->asTenant(function (): array {
            $bookingType = BookingType::factory()->sunbeds()->create(['name' => 'Sunbeds']);
            $area = Area::factory()->create([
                'booking_type_id' => $bookingType->id,
                'name' => 'Front row',
            ]);

            return [$bookingType, $area];
        });
    }

    #[Test]
    public function a_whole_row_is_created_in_one_go(): void
    {
        $this->post(route('dashboard.resources.store'), [
            'area_id' => $this->area->id,
            'prefix' => 'A',
            'from' => 1,
            'to' => 20,
        ])->assertRedirect();

        $labels = $this->asTenant(
            static fn () => Resource::query()->orderBy('sort_order')->pluck('label')->all()
        );

        $this->assertCount(20, $labels);
        $this->assertSame('A1', $labels[0]);
        $this->assertSame('A20', $labels[19]);
    }

    #[Test]
    public function resources_inherit_the_price_of_their_booking_type(): void
    {
        $this->asTenant(fn () => $this->bookingType->forceFill(['default_price_cents' => 2500])->save());

        $this->post(route('dashboard.resources.store'), [
            'area_id' => $this->area->id,
            'prefix' => 'A',
            'from' => 1,
            'to' => 3,
        ]);

        $prices = $this->asTenant(
            static fn () => Resource::query()->pluck('base_price_cents')->unique()->all()
        );

        $this->assertSame([2500], array_values($prices));
    }

    #[Test]
    public function an_area_can_charge_its_own_price(): void
    {
        $this->post(route('dashboard.resources.store'), [
            'area_id' => $this->area->id,
            'prefix' => 'A',
            'from' => 1,
            'to' => 2,
            'price' => '30.00',
        ]);

        $prices = $this->asTenant(
            static fn () => Resource::query()->pluck('base_price_cents')->unique()->values()->all()
        );

        $this->assertSame([3000], $prices);
    }

    #[Test]
    public function a_template_makes_every_resource_a_set(): void
    {
        $template = $this->asTenant(static fn (): ResourceTemplate => ResourceTemplate::create([
            'name' => 'Beach set',
            'is_composite' => true,
            'components' => [
                ['label' => 'Sunbed', 'qty' => 2],
                ['label' => 'Umbrella', 'qty' => 1],
            ],
            'capacity_min' => 1,
            'capacity_max' => 2,
            'base_price_cents' => 2500,
            'price_basis' => PriceBasis::PerResource,
            'meta' => ['shade' => 'umbrella'],
        ]));

        $this->post(route('dashboard.resources.store'), [
            'area_id' => $this->area->id,
            'prefix' => 'A',
            'from' => 1,
            'to' => 4,
            'template_id' => $template->id,
        ]);

        $resource = $this->asTenant(static fn (): ?Resource => Resource::query()->first());

        $this->assertNotNull($resource);
        $this->assertTrue($resource->is_composite);
        $this->assertSame('2 Sunbeds · Umbrella', $resource->componentSummary());
        $this->assertSame(['shade' => 'umbrella'], $resource->meta);

        // The template's own row must not leak into the copy.
        $this->assertArrayNotHasKey('name', $resource->meta);
    }

    #[Test]
    public function a_second_run_skips_the_labels_that_already_exist(): void
    {
        $payload = [
            'area_id' => $this->area->id,
            'prefix' => 'A',
            'from' => 1,
            'to' => 5,
        ];

        $this->post(route('dashboard.resources.store'), $payload);
        $this->post(route('dashboard.resources.store'), [...$payload, 'to' => 8]);

        $labels = $this->asTenant(static fn () => Resource::query()->pluck('label')->all());

        $this->assertCount(8, $labels);
        $this->assertSame(8, count(array_unique($labels)));
    }

    #[Test]
    public function adding_nothing_new_says_so_rather_than_failing_quietly(): void
    {
        $payload = [
            'area_id' => $this->area->id,
            'prefix' => 'A',
            'from' => 1,
            'to' => 3,
        ];

        $this->post(route('dashboard.resources.store'), $payload);
        $this->post(route('dashboard.resources.store'), $payload)
            ->assertSessionHasErrors('resources');

        $this->assertSame(3, $this->asTenant(static fn (): int => Resource::query()->count()));
    }

    #[Test]
    public function a_reversed_range_still_works(): void
    {
        $this->asTenant(function (): void {
            app(BulkCreateResources::class)->handle($this->area, 'B', 9, 5);
        });

        $labels = $this->asTenant(static fn () => Resource::query()->pluck('label')->all());

        $this->assertCount(5, $labels);
        $this->assertContains('B5', $labels);
        $this->assertContains('B9', $labels);
    }

    #[Test]
    public function one_venue_cannot_add_resources_to_another_ones_area(): void
    {
        $otherArea = $this->area;

        // A second account, with its own tenant, aiming at the first one's area.
        $this->signInAsOwner('Another Place');

        $this->post(route('dashboard.resources.store'), [
            'area_id' => $otherArea->id,
            'prefix' => 'X',
            'from' => 1,
            'to' => 3,
        ])->assertNotFound();
    }
}
