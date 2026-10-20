<?php

declare(strict_types=1);

namespace Tests\Feature\Subscriptions;

use App\Actions\Subscriptions\StartSubscription;
use App\Enums\Feature;
use App\Enums\PlanLimit;
use App\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Resource;
use App\Subscriptions\Entitlements;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\Concerns\BuildsVenueStructure;
use Tests\TestCase;

/**
 * What a plan allows (SPEC §4.5).
 *
 * Two rules carry the weight. At a limit nothing already there is touched —
 * not on a downgrade either, because a system that deletes a venue's tables
 * when their card changes is a system nobody trusts with a season. And the
 * trial allows everything: a demo that refuses things is a demo of the wrong
 * product.
 */
final class LimitsTest extends TestCase
{
    use ActsAsVenueOwner;
    use BuildsVenueStructure;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00', 'Europe/Athens'));

        $this->signInAsOwner('Demo Beach Bar');
    }

    #[Test]
    public function the_trial_allows_everything_with_no_plan_at_all(): void
    {
        $this->assertSame(TenantStatus::Trialing, $this->tenant->status);

        $this->asTenant(function (): void {
            $entitlements = app(Entitlements::class);

            $this->assertTrue($entitlements->isOnTrial());
            $this->assertNull($entitlements->limit(PlanLimit::Resources));

            foreach (Feature::cases() as $feature) {
                $this->assertTrue(
                    $entitlements->allows($feature),
                    $feature->value.' should be available during the trial.'
                );
            }
        });
    }

    #[Test]
    public function a_plan_without_a_feature_refuses_it(): void
    {
        $this->subscribe(Plan::factory()->without([Feature::FloorPlanEditor]));

        $this->asTenant(function (): void {
            $entitlements = app(Entitlements::class);

            $this->assertFalse($entitlements->allows(Feature::FloorPlanEditor));
            $this->assertTrue($entitlements->allows(Feature::EmbedWidget));
        });
    }

    #[Test]
    public function at_the_limit_the_next_one_is_refused_with_the_number(): void
    {
        $this->subscribe(Plan::factory()->limited([PlanLimit::Resources->value => 4]));

        $this->asTenant(function (): void {
            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');

            $this->resourcesIn($area, 'A', 4);

            $entitlements = app(Entitlements::class);

            $this->assertFalse($entitlements->hasRoomFor(PlanLimit::Resources));
            $this->assertStringContainsString('4 tables or sunbeds', $entitlements->refusalFor(PlanLimit::Resources));
        });
    }

    #[Test]
    public function the_whole_batch_is_checked_before_any_of_it_is_written(): void
    {
        $this->subscribe(Plan::factory()->limited([PlanLimit::Resources->value => 12]));

        $response = null;

        $area = $this->asTenant(function () {
            $type = $this->sunbedType();

            return $this->areaIn($type, 'Front row');
        });

        // Twenty asked for, room for twelve. Half a row of sunbeds is worse
        // than none: the owner then has to work out which ones exist.
        $response = $this->post(route('dashboard.resources.store'), [
            'area_id' => $area->id,
            'prefix' => 'A',
            'from' => 1,
            'to' => 20,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertSame(0, $this->asTenant(static fn (): int => Resource::query()->count()));
    }

    #[Test]
    public function a_downgrade_never_removes_anything(): void
    {
        $this->subscribe(Plan::factory());

        $this->asTenant(function (): void {
            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');
            $this->resourcesIn($area, 'A', 8);
        });

        // Moved onto a plan whose limit they are already over.
        $this->subscribe(Plan::factory()->limited([PlanLimit::Resources->value => 3]));

        $this->asTenant(function (): void {
            // Everything stays. They simply cannot add a ninth.
            $this->assertSame(8, Resource::query()->count());
            $this->assertFalse(app(Entitlements::class)->hasRoomFor(PlanLimit::Resources));
        });
    }

    #[Test]
    public function an_expired_account_may_add_nothing_and_keeps_everything(): void
    {
        $this->asTenant(function (): void {
            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');
            $this->resourcesIn($area, 'A', 3);
        });

        $this->tenant->forceFill(['status' => TenantStatus::Expired])->save();

        $this->asTenant(function (): void {
            $entitlements = app(Entitlements::class);

            $this->assertTrue($entitlements->hasLapsed());
            $this->assertSame(0, $entitlements->limit(PlanLimit::Resources));
            $this->assertFalse($entitlements->allows(Feature::EmbedWidget));

            // And nothing was taken away.
            $this->assertSame(3, Resource::query()->count());

            $this->assertStringContainsString(
                'Everything already here is safe',
                $entitlements->refusalFor(PlanLimit::Resources),
            );
        });
    }

    #[Test]
    public function bookings_are_counted_for_the_current_month_only(): void
    {
        $this->subscribe(Plan::factory()->limited([PlanLimit::ReservationsPerMonth->value => 2]));

        $this->asTenant(function (): void {
            \App\Models\Reservation::factory()->count(2)->create(['created_at' => now()]);

            $this->assertFalse(app(Entitlements::class)->hasRoomFor(PlanLimit::ReservationsPerMonth));
        });

        // Next month starts clean. The limit is a monthly allowance, not a
        // lifetime one.
        $this->travelTo(CarbonImmutable::parse('2026-08-02 09:00', 'Europe/Athens'));

        $this->asTenant(function (): void {
            app(Entitlements::class)->forget();

            $this->assertTrue(app(Entitlements::class)->hasRoomFor(PlanLimit::ReservationsPerMonth));
        });
    }

    #[Test]
    public function a_venue_over_its_monthly_allowance_stops_taking_bookings_politely(): void
    {
        $this->subscribe(Plan::factory()->limited([PlanLimit::ReservationsPerMonth->value => 1]));

        $this->asTenant(function (): void {
            $this->sunbedType(['name' => 'Sunbeds']);
            \App\Models\Reservation::factory()->create(['created_at' => now()]);
        });

        auth()->logout();
        app(\App\Tenancy\TenantContext::class)->forget();

        // The guest is told the same calm sentence as for any other closure.
        // A tourist on a beach has no use for the venue's billing situation.
        $this->get(route('public.search', [$this->tenant, 'type' => 'sunbeds']))
            ->assertStatus(503)
            ->assertSee('temporarily unavailable');

        $this->getJson("/api/internal/{$this->tenant->slug}/times?type=sunbeds&date=2026-07-04")
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'bookings_closed');
    }

    private function subscribe(mixed $planFactory): void
    {
        $plan = $planFactory instanceof Plan ? $planFactory : $planFactory->create();

        $this->asTenant(fn () => app(StartSubscription::class)->handle($this->tenant, $plan));

        // The resolved plan is cached for the request, and the test has just
        // changed it underneath.
        app(Entitlements::class)->forget();
    }
}
