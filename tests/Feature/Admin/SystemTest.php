<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\PaymentStatus;
use App\Models\CommandRun;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Platform\PlatformHealth;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsPlatformAdmin;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\Concerns\BuildsVenueStructure;
use Tests\TestCase;

/**
 * The parts nobody watches (SPEC §13.2).
 *
 * The whole point of this screen is a failure mode with no symptoms: a cron
 * that is not there does not throw, does not log and does not email anybody.
 * So the first thing tested is that a command which has never run says so.
 */
final class SystemTest extends TestCase
{
    use ActsAsPlatformAdmin;
    use ActsAsVenueOwner;
    use BuildsVenueStructure;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00', 'Europe/Athens'));

        Mail::fake();

        $this->signInAsOwner('Demo Beach Bar');
        $this->signInAsPlatformAdmin();
    }

    #[Test]
    public function a_command_that_has_never_run_says_never(): void
    {
        $this->get(route('admin.system'))
            ->assertOk()
            ->assertSee('never')

            // And why it matters, next to the problem — "tenants:purge is
            // late" means nothing on its own.
            ->assertSee('nobody is ever chased');
    }

    #[Test]
    public function a_command_records_itself_when_it_runs(): void
    {
        $this->artisan('subscriptions:advance')->assertSuccessful();

        $run = CommandRun::query()->where('command', 'subscriptions:advance')->firstOrFail();

        // The sentence the command printed, kept — because a timestamp says
        // the cron fired and not that it worked.
        $this->assertTrue((bool) $run->ok);
        $this->assertStringContainsString('Checked', (string) $run->summary);

        $this->get(route('admin.system'))
            ->assertOk()
            ->assertSee('Checked')
            ->assertDontSee('nobody is ever chased');
    }

    #[Test]
    public function running_it_twice_leaves_one_row(): void
    {
        $this->artisan('subscriptions:advance')->assertSuccessful();
        $this->travel(1)->days();
        $this->artisan('subscriptions:advance')->assertSuccessful();

        // One row per command, not a log. `holds:release` runs every minute,
        // so a history would be fourteen hundred rows a day of something
        // nobody reads.
        $this->assertSame(1, CommandRun::query()->where('command', 'subscriptions:advance')->count());
    }

    #[Test]
    public function a_command_that_has_not_run_for_two_days_is_overdue(): void
    {
        $this->artisan('tenants:purge')->assertSuccessful();

        $run = CommandRun::query()->where('command', 'tenants:purge')->firstOrFail();

        // One missed night is a busy host; two is a cron that is not there.
        $this->assertFalse($run->isOverdue(24));

        $this->travel(3)->days();

        $this->assertTrue($run->fresh()->isOverdue(24));
    }

    #[Test]
    public function a_dry_run_of_the_purge_is_not_recorded_as_a_run(): void
    {
        $this->artisan('tenants:purge', ['--dry-run' => true])->assertSuccessful();

        // It did not do the work. A System screen saying the purge ran last
        // night when all it did was list what it would have done is worse
        // than saying nothing.
        $this->assertSame(0, CommandRun::query()->where('command', 'tenants:purge')->count());
    }

    #[Test]
    public function the_page_says_nothing_to_do_when_everything_has_run(): void
    {
        foreach (array_keys(PlatformHealth::SCHEDULED) as $command) {
            CommandRun::record($command, 'Fine.');
        }

        $this->get(route('admin.system'))
            ->assertOk()
            ->assertSee('Nothing to do');
    }

    #[Test]
    public function a_payment_waiting_too_long_on_a_webhook_is_counted(): void
    {
        $this->asTenant(function (): void {
            $type = $this->sunbedType();
            $area = $this->areaIn($type, 'Front row');
            $this->resourcesIn($area, 'A', 1);

            Payment::factory()->create([
                'status' => PaymentStatus::Pending,
                'created_at' => now()->subHours(3),
            ]);
        });

        /*
         * Pending for more than an hour means the gateway said it would tell
         * us and has not (§24.2). A figure here that stays up means
         * `payments:reconcile` is not running either — which is the
         * scheduler row above, not a gateway problem.
         */
        $this->get(route('admin.system'))
            ->assertOk()
            ->assertSee('Waiting on a webhook');

        $this->assertSame(1, app(PlatformHealth::class)->stalePendingPayments());
        $this->assertFalse(app(PlatformHealth::class)->looksHealthy());
    }

    #[Test]
    public function the_money_screen_puts_a_bounced_payment_first(): void
    {
        $plan = Plan::factory()->create(['price_cents' => 7000]);

        $this->asTenant(fn () => Subscription::query()->create([
            'plan_id' => $plan->getKey(),
            'status' => \App\Enums\TenantStatus::PastDue,
            'current_period_start' => now()->subDays(10),
            'current_period_end' => now()->addDays(20),
            'price_cents' => 7000,
            'currency' => 'EUR',
        ]));

        $this->asTenant(fn () => Subscription::query()->firstOrFail()
            ->forceFill(['payment_failed_at' => now()->subDay()])
            ->save());

        $this->get(route('admin.subscriptions'))
            ->assertOk()
            ->assertSee('Demo Beach Bar')
            ->assertSee('failed')

            // Which of the three emails has gone out, so "we never told
            // them" and "they have ignored three" are different rows.
            ->assertSee('not chased yet');
    }

    #[Test]
    public function a_venue_owner_cannot_see_either_screen(): void
    {
        // The owner from setUp, signed back in: calling signInAsOwner again
        // would try to register a second account with the same email.
        $this->actingAs($this->owner);

        $this->get(route('admin.system'))->assertNotFound();
        $this->get(route('admin.subscriptions'))->assertNotFound();
    }
}
