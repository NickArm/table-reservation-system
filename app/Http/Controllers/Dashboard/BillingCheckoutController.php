<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Actions\Subscriptions\CancelSubscription;
use App\Actions\Subscriptions\StartSubscription;
use App\Billing\BillingManager;
use App\Billing\Exceptions\BillingUnavailable;
use App\Enums\BillingProvider;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Starting and finishing a subscription payment (SPEC §4.3).
 *
 * Two halves, and the second one is where the care goes. Coming back from a
 * provider proves nothing: the return address is a plain URL that anybody can
 * type, and a browser that never comes back does not mean the payment failed.
 * So the return asks the provider what happened, and the account is only
 * marked as paying on the provider's own answer.
 *
 * The webhook does the same thing from the other direction, which is the
 * safety net for the guest who closed the tab on the payment screen.
 */
final class BillingCheckoutController extends Controller
{
    public function __construct(
        private readonly BillingManager $billing,
        private readonly StartSubscription $subscriptions,
    ) {}

    /**
     * The owner picked a plan.
     */
    public function start(
        Request $request,
        Plan $plan,
        TenantContext $context,
    ): RedirectResponse {
        $tenant = $context->getOrFail();

        /*
         * The plan has to be one this account was actually offered. A plan is
         * not tenant-scoped — it belongs to the platform — so without this
         * check an account could subscribe to another venue's private plan by
         * guessing a number, which is how somebody ends up on a negotiated
         * price that was never theirs.
         */
        $offered = Plan::query()
            ->offeredTo($tenant)
            ->whereKey($plan->getKey())
            ->exists();

        if (! $offered) {
            abort(404);
        }

        $provider = $this->chosenProvider($request);

        if ($provider === null) {
            return back()->with('error', BillingUnavailable::noProvider()->getMessage());
        }

        try {
            $checkout = $this->billing->for($provider)->startCheckout($plan, $tenant);
        } catch (BillingUnavailable $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            // Anything else is ours. The owner gets a sentence they can act
            // on; we get the detail in the log.
            Log::error('Could not start a subscription checkout.', [
                'tenant' => $tenant->slug,
                'plan' => $plan->slug,
                'provider' => $provider->value,
                'error' => $exception->getMessage(),
            ]);

            return back()->with(
                'error',
                'We could not reach the payment provider. Nothing has been charged — '
                .'please try again in a moment.'
            );
        }

        /*
         * Which plan this was for, remembered across the trip to the
         * provider. Stripe will hand the session back and PayPal the
         * subscription, but neither returns the *plan row* — and resolving it
         * from the provider's price id would mean a second lookup that can
         * disagree with what the owner actually pressed.
         */
        $request->session()->put('billing.pending', [
            'plan_id' => $plan->getKey(),
            'provider' => $provider->value,
            'reference' => $checkout->reference,
        ]);

        return redirect()->away($checkout->url);
    }

    /**
     * Back from the provider.
     */
    public function returned(
        Request $request,
        string $provider,
        TenantContext $context,
    ): RedirectResponse {
        $tenant = $context->getOrFail();

        $chosen = BillingProvider::tryFrom($provider);

        /** @var array{plan_id: int, provider: string, reference: string}|null $pending */
        $pending = $request->session()->pull('billing.pending');

        if ($chosen === null || $pending === null) {
            return redirect()->route('dashboard.billing');
        }

        // Stripe fills its own placeholder on the way back; PayPal returns
        // the subscription id it gave us before we left.
        $reference = (string) ($request->query('reference') ?: $pending['reference']);

        try {
            $state = $this->billing->for($chosen)->fetch($reference);
        } catch (Throwable $exception) {
            Log::warning('Could not read back a subscription after checkout.', [
                'tenant' => $tenant->slug,
                'provider' => $chosen->value,
                'error' => $exception->getMessage(),
            ]);

            return $this->waiting();
        }

        if (! $state->isUsable()) {
            /*
             * Not an error. PayPal in particular can still be approving when
             * the browser arrives, and a webhook will finish the job within
             * seconds — so the owner is told to wait rather than told it
             * failed, which would send them to pay a second time.
             */
            return $this->waiting();
        }

        $plan = Plan::query()->find($pending['plan_id']);

        if (! $plan instanceof Plan) {
            return redirect()->route('dashboard.billing');
        }

        $this->activate($tenant, $plan, $chosen, $state->subscriptionId, $state->customerId);

        return redirect()
            ->route('dashboard.billing')
            ->with('status', 'Thank you — your subscription is active.');
    }

    /**
     * They want to stop.
     *
     * At the provider first, then here. The other order would mark the
     * account as cancelled and then fail to stop the charge, which is the one
     * outcome worth going out of our way to avoid: still being billed for
     * something the screen says is cancelled.
     *
     * Nothing is taken away today — `CancelSubscription` sets
     * `cancel_at_period_end`, so they keep what they paid for until the
     * period runs out (§4.4).
     */
    public function cancel(
        TenantContext $context,
        CancelSubscription $cancellation,
    ): RedirectResponse {
        $context->getOrFail();

        $subscription = Subscription::query()->with('plan')->first();

        if (! $subscription instanceof Subscription || $subscription->cancelled_at !== null) {
            return redirect()->route('dashboard.billing');
        }

        $provider = $subscription->provider;
        $reference = (string) $subscription->provider_subscription_id;

        if ($provider instanceof BillingProvider
            && $provider !== BillingProvider::Manual
            && $reference !== ''
        ) {
            try {
                $this->billing->for($provider)->cancel($reference);
            } catch (Throwable $exception) {
                Log::error('Could not cancel a subscription at the provider.', [
                    'provider' => $provider->value,
                    'subscription' => $reference,
                    'error' => $exception->getMessage(),
                ]);

                return back()->with(
                    'error',
                    'We could not reach the payment provider, so nothing has been '
                    .'cancelled yet. Please try again in a moment — or write to us and '
                    .'we will stop it by hand.'
                );
            }
        }

        $cancellation->handle($subscription, 'Cancelled by the venue.');

        $until = $subscription->current_period_end?->format('j M Y');

        return redirect()
            ->route('dashboard.billing')
            ->with('status', $until === null
                ? 'Your subscription has been cancelled.'
                : 'Your subscription has been cancelled. You keep everything until '
                    .$until.'.');
    }

    private function activate(
        Tenant $tenant,
        Plan $plan,
        BillingProvider $provider,
        ?string $subscriptionId,
        ?string $customerId,
    ): void {
        $this->subscriptions->handle(
            $tenant,
            $plan,
            $provider,
            $subscriptionId,
            $customerId,
        );
    }

    private function waiting(): RedirectResponse
    {
        return redirect()
            ->route('dashboard.billing')
            ->with(
                'status',
                'Your payment is being confirmed. This page updates by itself once it '
                .'is through — there is no need to pay again.'
            );
    }

    /**
     * Which provider to send them to.
     *
     * The owner may pick, but normally does not: one is configured and it is
     * simply used. Offering a choice between two payment buttons is the kind
     * of decision that loses people (§1.1).
     */
    private function chosenProvider(Request $request): ?BillingProvider
    {
        $asked = BillingProvider::tryFrom((string) $request->string('provider'));

        if ($asked !== null && in_array($asked, $this->billing->available(), true)) {
            return $asked;
        }

        return $this->billing->preferred();
    }
}
