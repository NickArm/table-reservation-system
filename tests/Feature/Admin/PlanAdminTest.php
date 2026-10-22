<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AuditAction;
use App\Enums\BillingPeriod;
use App\Enums\BillingProvider;
use App\Enums\Feature;
use App\Enums\PlanLimit;
use App\Models\AuditEntry;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsPlatformAdmin;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * Writing what the platform sells (SPEC §4.1, §13.2).
 */
final class PlanAdminTest extends TestCase
{
    use ActsAsPlatformAdmin;
    use ActsAsVenueOwner;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAsOwner('Demo Beach Bar');

        $this->signInAsPlatformAdmin();
    }

    #[Test]
    public function a_plan_can_be_written_and_is_not_buyable_until_it_exists_at_the_provider(): void
    {
        $this->post(route('admin.plans.store'), $this->fields())
            ->assertRedirect(route('admin.plans.index'))
            ->assertSessionHasNoErrors();

        $plan = Plan::query()->where('slug', 'season')->firstOrFail();

        $this->assertSame(45000, (int) $plan->price_cents);
        $this->assertSame(BillingPeriod::Yearly, $plan->billing_period);

        // Written here first, set up at Stripe after. Until the id is on it
        // the billing page shows no button, because a button that dies on the
        // other side is worse than none.
        $this->assertFalse($plan->sellableThrough(BillingProvider::Stripe));
        $this->assertFalse($plan->sellableThrough(BillingProvider::PayPal));

        $this->get(route('admin.plans.index'))->assertOk()->assertSee('not set up');

        $this->assertTrue(
            AuditEntry::query()->where('action', AuditAction::PlanCreated)->exists()
        );
    }

    #[Test]
    public function a_plan_written_for_one_account_is_private_whatever_the_checkbox_says(): void
    {
        $other = Tenant::factory()->create(['name' => 'Another Place']);

        $this->post(route('admin.plans.store'), [
            ...$this->fields(),
            'custom_for_tenant_id' => $other->getKey(),

            // Ticked by mistake, or left over from the last plan. Either way
            // a negotiated price must never turn up on everybody's page.
            'is_public' => '1',
        ])->assertRedirect();

        $plan = Plan::query()->where('slug', 'season')->firstOrFail();

        $this->assertFalse((bool) $plan->is_public);
        $this->assertSame((int) $other->getKey(), (int) $plan->custom_for_tenant_id);

        // And it only appears for the account it was written for.
        $this->assertTrue(Plan::query()->offeredTo($other)->whereKey($plan)->exists());
        $this->assertFalse(Plan::query()->offeredTo($this->tenant)->whereKey($plan)->exists());
    }

    #[Test]
    public function a_blank_limit_means_no_limit_rather_than_zero(): void
    {
        $this->post(route('admin.plans.store'), [
            ...$this->fields(),
            'limits' => [
                PlanLimit::Venues->value => '1',
                PlanLimit::ReservationsPerMonth->value => '',
            ],
        ])->assertRedirect();

        $plan = Plan::query()->where('slug', 'season')->firstOrFail();

        $this->assertSame(1, $plan->limit(PlanLimit::Venues));

        // Zero would mean an account that may take no bookings at all, which
        // is not what an empty box means to the person typing it.
        $this->assertNull($plan->limit(PlanLimit::ReservationsPerMonth));
    }

    #[Test]
    public function a_feature_left_unticked_is_off(): void
    {
        $this->post(route('admin.plans.store'), [
            ...$this->fields(),
            'features' => [Feature::EmbedWidget->value => '1'],
        ])->assertRedirect();

        $plan = Plan::query()->where('slug', 'season')->firstOrFail();

        $this->assertTrue($plan->allows(Feature::EmbedWidget));
        $this->assertFalse($plan->allows(Feature::MultiVenue));
    }

    #[Test]
    public function changing_a_price_does_not_change_what_anybody_is_already_paying(): void
    {
        $plan = Plan::factory()->create(['slug' => 'monthly', 'price_cents' => 7000]);

        $this->patch(route('admin.plans.update', $plan), [
            ...$this->fields(),
            'slug' => 'monthly',
            'price' => '85.00',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(8500, (int) $plan->refresh()->price_cents);

        // The screen has to say so, because this is the one thing about it
        // somebody will later be certain worked the other way round.
        $this->get(route('admin.plans.index'))->assertOk();
        $this->get(route('admin.plans.edit', $plan))
            ->assertOk()
            ->assertSee('does not change what anybody is already paying');
    }

    #[Test]
    public function retiring_a_plan_is_recorded_as_a_retirement(): void
    {
        $plan = Plan::factory()->create(['slug' => 'old-thing']);

        $this->patch(route('admin.plans.update', $plan), [
            ...$this->fields(),
            'slug' => 'old-thing',

            // '0' rather than omitting the key: the `boolean` rule rejects a
            // missing value, so leaving it out fails validation and the
            // update quietly never happens — which is how this test passed
            // for the wrong reason the first time it was written.
            'is_active' => '0',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse((bool) $plan->refresh()->is_active);

        $this->assertTrue(
            AuditEntry::query()->where('action', AuditAction::PlanRetired)->exists()
        );
    }

    #[Test]
    public function two_plans_cannot_share_a_slug(): void
    {
        Plan::factory()->create(['slug' => 'season']);

        // The slug is how the seeder and the code find a plan, so a duplicate
        // would make `where('slug', …)` return whichever came first.
        $this->post(route('admin.plans.store'), $this->fields())
            ->assertSessionHasErrors('slug');
    }

    /**
     * @return array<string, mixed>
     */
    private function fields(): array
    {
        return [
            'name' => 'Season',
            'slug' => 'season',
            'description' => 'Five months, paid once.',
            'billing_period' => BillingPeriod::Yearly->value,
            'price' => '450.00',
            'trial_days' => 15,
            'sort_order' => 30,
            'is_public' => '1',
            'is_active' => '1',
            'limits' => [PlanLimit::Venues->value => '1'],
            'features' => [Feature::EmbedWidget->value => '1'],
        ];
    }
}
