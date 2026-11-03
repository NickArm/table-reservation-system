<?php

declare(strict_types=1);

namespace Tests\Feature\Subscriptions;

use App\Actions\Subscriptions\SendDunningNotices;
use App\Actions\Subscriptions\StartSubscription;
use App\Actions\Subscriptions\WarnBeforeManualLapse;
use App\Enums\BillingProvider;
use App\Enums\TenantStatus;
use App\Mail\ManualRenewalsDue;
use App\Mail\PaymentFailedNotice;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsPlatformAdmin;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * Accounts that pay outside the system (SPEC §4.1).
 *
 * Everything else in the lifecycle announces itself: a card renews on its
 * own, and a card that fails writes a `payment_failed_at` that the dunning
 * emails read. An invoice sent by a person writes nothing at all — so the
 * two things worth testing are that the automatic chasing never touches
 * these accounts, and that somebody is told before one runs out.
 *
 * The first of those is true today by construction rather than by a rule
 * anybody wrote down, which is exactly why it has a test: a refactor that
 * widened the dunning query would break it in complete silence, and the
 * symptom would be a customer being emailed about a card we have never had.
 */
final class HandBilledAccountsTest extends TestCase
{
    use ActsAsPlatformAdmin;
    use ActsAsVenueOwner;
    use RefreshDatabase;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-04-01 09:00', 'Europe/Athens'));

        Mail::fake();

        $this->signInAsOwner('Crystal Seaside');

        $plan = Plan::factory()->create(['name' => 'Monthly', 'price_cents' => 7000]);

        $this->subscription = $this->asTenant(fn (): Subscription => app(StartSubscription::class)
            ->handle($this->tenant, $plan, BillingProvider::Manual));

        $this->signInAsPlatformAdmin();
    }

    #[Test]
    public function the_dunning_emails_never_chase_one(): void
    {
        /*
         * Pushed into the past so that an account with an expired period is
         * sitting there — the state in which a card-billed account would be
         * being chased daily.
         */
        $this->subscription->forceFill([
            'current_period_end' => now()->subDays(5),
        ])->save();

        $chased = app(SendDunningNotices::class)->handle();

        $this->assertSame(0, $chased['sent']);
        Mail::assertNotSent(PaymentFailedNotice::class);
    }

    #[Test]
    public function it_tells_the_platform_before_the_period_runs_out(): void
    {
        config(['tenancy.manual_notice_days' => 10]);

        $this->subscription->forceFill([
            'current_period_start' => now()->subDays(25),
            'current_period_end' => now()->addDays(6),
        ])->save();

        $result = app(WarnBeforeManualLapse::class)->handle();

        $this->assertSame(1, $result['due']);
        $this->assertSame(1, $result['sent']);

        Mail::assertSent(
            ManualRenewalsDue::class,
            // To us, never to the customer: they agreed to pay an invoice,
            // not to be chased by the software.
            fn (ManualRenewalsDue $mail): bool => $mail->hasTo($this->admin->email)
                && ! $mail->hasTo($this->owner->email),
        );
    }

    #[Test]
    public function it_says_nothing_while_the_period_is_still_a_long_way_off(): void
    {
        config(['tenancy.manual_notice_days' => 10]);

        $this->subscription->forceFill([
            'current_period_end' => now()->addDays(20),
        ])->save();

        $this->assertSame(0, app(WarnBeforeManualLapse::class)->handle()['due']);

        Mail::assertNothingSent();
    }

    #[Test]
    public function it_does_not_say_the_same_thing_every_night(): void
    {
        config(['tenancy.manual_notice_days' => 10]);

        $this->subscription->forceFill([
            'current_period_start' => now()->subDays(25),
            'current_period_end' => now()->addDays(6),
        ])->save();

        app(WarnBeforeManualLapse::class)->handle();

        $this->travel(1)->days();

        $this->assertSame(0, app(WarnBeforeManualLapse::class)->handle()['due']);

        Mail::assertSentCount(1);
    }

    #[Test]
    public function the_next_period_is_warned_about_on_its_own(): void
    {
        config(['tenancy.manual_notice_days' => 10]);

        $this->subscription->forceFill([
            'current_period_start' => now()->subDays(25),
            'current_period_end' => now()->addDays(6),
        ])->save();

        app(WarnBeforeManualLapse::class)->handle();

        /*
         * The invoice is paid and the period is moved on — which is what
         * "Extend the subscription" does. Nothing clears the notice flag,
         * and nothing needs to: it is compared against the period start, so
         * a new period leaves the old notice behind it.
         */
        $this->subscription->forceFill([
            'current_period_start' => now()->addDays(6),
            'current_period_end' => now()->addDays(36),
        ])->save();

        $this->travel(30)->days();

        $this->assertSame(1, app(WarnBeforeManualLapse::class)->handle()['due']);
    }

    #[Test]
    public function an_account_switched_off_by_hand_is_left_out_of_it(): void
    {
        config(['tenancy.manual_notice_days' => 10]);

        $this->subscription->forceFill([
            'current_period_end' => now()->addDays(3),
        ])->save();

        $this->tenant->forceFill(['status' => TenantStatus::Suspended])->save();

        // Somebody switched that one off deliberately. A reminder to invoice
        // an account we have cut off is noise.
        $this->assertSame(0, app(WarnBeforeManualLapse::class)->handle()['due']);
    }

    #[Test]
    public function a_card_account_is_none_of_its_business(): void
    {
        config(['tenancy.manual_notice_days' => 10]);

        $this->subscription->forceFill([
            'provider' => BillingProvider::Stripe,
            'current_period_end' => now()->addDays(3),
        ])->save();

        // That one renews itself, and if it fails it says so.
        $this->assertSame(0, app(WarnBeforeManualLapse::class)->handle()['due']);
    }
}
