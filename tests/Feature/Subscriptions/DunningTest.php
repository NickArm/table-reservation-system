<?php

declare(strict_types=1);

namespace Tests\Feature\Subscriptions;

use App\Actions\Subscriptions\AdvanceLifecycle;
use App\Actions\Subscriptions\RecordPaymentFailure;
use App\Actions\Subscriptions\RenewSubscription;
use App\Actions\Subscriptions\SendDunningNotices;
use App\Actions\Subscriptions\StartSubscription;
use App\Enums\TenantStatus;
use App\Mail\PaymentFailedNotice;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsVenueOwner;
use Tests\TestCase;

/**
 * Chasing a payment that did not go through (SPEC §4.3).
 *
 * Three emails, on days one, three and seven, and then the booking page
 * speaks for itself. What is being tested is mostly restraint: not sending
 * twice, not sending a backlog all at once, and stopping the moment the money
 * arrives.
 */
final class DunningTest extends TestCase
{
    use ActsAsVenueOwner;
    use RefreshDatabase;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00', 'Europe/Athens'));

        Mail::fake();

        $this->signInAsOwner('Demo Beach Bar');

        $plan = Plan::factory()->create(['price_cents' => 7000]);

        $this->subscription = $this->asTenant(fn (): Subscription => app(StartSubscription::class)
            ->handle($this->tenant, $plan));
    }

    #[Test]
    public function nothing_is_sent_on_the_day_the_payment_fails(): void
    {
        Mail::fake();

        $this->failThePayment('Your card has expired.');
        $this->sweep();

        // The provider is still trying. An email within the hour is noise,
        // and the retry may well succeed before anybody reads it.
        Mail::assertNothingSent();
    }

    #[Test]
    public function the_first_notice_goes_out_the_next_day_and_names_the_date(): void
    {
        $this->failThePayment('Your card has expired.');

        Mail::fake();
        $this->travel(1)->days();
        $this->sweep();

        Mail::assertSent(PaymentFailedNotice::class, function (PaymentFailedNotice $mail): bool {
            $mail->assertTo($this->owner->email);

            // The date is the one fact that decides whether the owner acts
            // today or next week, so it has to be in the email, and it has to
            // be the date the account will actually lock on.
            $this->assertNotNull($mail->locksOn);
            $this->assertFalse($mail->stopped());
            $this->assertSame(1, $mail->day);

            return true;
        });
    }

    #[Test]
    public function the_reason_the_provider_gave_is_passed_on(): void
    {
        $this->failThePayment('Your card has expired.');

        Mail::fake();
        $this->travel(1)->days();
        $this->sweep();

        Mail::assertSent(PaymentFailedNotice::class, fn (PaymentFailedNotice $mail): bool => $mail->reason === 'Your card has expired.');
    }

    #[Test]
    public function the_same_notice_is_never_sent_twice(): void
    {
        $this->failThePayment();

        $this->travel(1)->days();
        $this->sweep();

        Mail::fake();

        // Two more sweeps on the same day, which is what a cron that runs
        // hourly by mistake would do.
        $this->sweep();
        $this->sweep();

        Mail::assertNothingSent();
    }

    #[Test]
    public function all_three_go_out_over_the_week_and_then_it_stops(): void
    {
        $this->failThePayment();

        Mail::fake();

        $days = [];

        foreach (range(1, 14) as $ignored) {
            $this->travel(1)->days();
            $this->sweep();

            $days[] = $this->reload()->dunning_sent_for;
        }

        // One per mark, in order, and nothing after the seventh day.
        Mail::assertSentCount(3);
        $this->assertSame(7, (int) $this->reload()->dunning_sent_for);
        $this->assertSame([1, 1, 3, 3, 3, 3, 7, 7, 7, 7, 7, 7, 7, 7], array_map('intval', $days));
    }

    #[Test]
    public function a_sweep_that_was_missed_for_days_does_not_send_a_backlog(): void
    {
        $this->failThePayment();

        Mail::fake();

        // Four days with no cron — a host that ran out of disk, say.
        $this->travel(4)->days();
        $this->sweep();

        // One email, the one that is actually current. Two in the same minute
        // read as a system that has lost track of itself.
        Mail::assertSentCount(1);

        Mail::assertSent(PaymentFailedNotice::class, fn (PaymentFailedNotice $mail): bool => $mail->day === 3);
    }

    #[Test]
    public function the_last_notice_says_plainly_that_the_page_has_stopped(): void
    {
        // Failure at the very end of the paid period, so the grace days run
        // out inside the week and the account is locked by day seven.
        $this->asTenant(fn () => $this->subscription->forceFill([
            'current_period_end' => now()->addDay(),
        ])->save());

        $this->failThePayment();

        Mail::fake();
        $this->travel(7)->days();

        // The order the nightly command uses: statuses first, then the
        // emails, so a notice can say whether the page has already stopped.
        app(AdvanceLifecycle::class)->handle();
        $this->sweep();

        Mail::assertSent(PaymentFailedNotice::class, function (PaymentFailedNotice $mail): bool {
            $this->assertTrue($mail->stopped());
            $this->assertSame(7, $mail->day);

            return true;
        });

        $this->assertSame(TenantStatus::Expired, $this->tenant->refresh()->status);
    }

    #[Test]
    public function a_payment_that_goes_through_stops_the_chasing_at_once(): void
    {
        $this->failThePayment();

        $this->travel(1)->days();
        $this->sweep();

        $this->asTenant(fn () => app(RenewSubscription::class)->handle($this->reload()));

        Mail::fake();

        $this->travel(10)->days();
        $this->sweep();

        Mail::assertNothingSent();

        // And the mark is cleared, so a card that fails again next year is
        // chased from the beginning rather than skipped.
        $this->assertNull($this->reload()->dunning_sent_for);
    }

    #[Test]
    public function the_date_in_the_email_is_the_date_the_account_locks_on(): void
    {
        // A card that fails in the middle of a paid month: the grace days
        // start at the end of that month, not at the failure. The email has
        // to say the same date the lock uses, which is why it asks for it
        // rather than working it out again.
        $periodEnd = now()->addDays(20)->toImmutable();

        $this->asTenant(fn () => $this->subscription
            ->forceFill(['current_period_end' => $periodEnd])
            ->save());

        $this->failThePayment();

        Mail::fake();
        $this->travel(1)->days();
        $this->sweep();

        Mail::assertSent(PaymentFailedNotice::class, function (PaymentFailedNotice $mail) use ($periodEnd): bool {
            $expected = $periodEnd->addDays((int) config('tenancy.grace_days'));

            $this->assertSame(
                $expected->format('Y-m-d'),
                $mail->locksOn?->format('Y-m-d'),
            );

            return true;
        });
    }

    /**
     * The provider says the card did not work.
     *
     * Not called `fail()`: PHPUnit's `Assert::fail()` is final and static, so
     * a method of that name here is a fatal error rather than a test failure.
     */
    private function failThePayment(?string $reason = null): void
    {
        $this->asTenant(fn () => app(RecordPaymentFailure::class)->handle($this->reload(), $reason));
    }

    private function sweep(): void
    {
        app(SendDunningNotices::class)->handle();
    }

    private function reload(): Subscription
    {
        return $this->asTenant(static fn (): Subscription => Subscription::query()->firstOrFail());
    }
}
