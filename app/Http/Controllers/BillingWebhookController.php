<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Subscriptions\CancelSubscription;
use App\Actions\Subscriptions\RecordPaymentFailure;
use App\Actions\Subscriptions\RenewSubscription;
use App\Billing\BillingManager;
use App\Billing\BillingNotice;
use App\Enums\BillingEventKind;
use App\Enums\BillingProvider;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Tenancy\Concerns\RunsAsTenant;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What the platform's own provider tells us about subscriptions (SPEC §4.3).
 *
 * Not the same thing as `WebhookController`, which handles a *venue's*
 * gateway telling that venue about a guest's payment. This one is the
 * platform's single Stripe or PayPal account, and there is exactly one
 * endpoint per provider rather than one per account — it is our secret, not
 * the venue's.
 *
 * This is the half of billing that actually keeps accounts alive. A renewal
 * eleven months from now arrives here and nowhere else: nobody is sitting in
 * a browser when a card is charged for the second time.
 *
 * Everything answers 200. A provider that gets anything else retries for
 * hours and then gives up, and a webhook we cannot make sense of is not a
 * reason to have it sent again — it is a reason to look in the log.
 */
final class BillingWebhookController extends Controller
{
    use RunsAsTenant;

    public function __construct(
        private readonly BillingManager $billing,
        private readonly RenewSubscription $renew,
        private readonly RecordPaymentFailure $failure,
        private readonly CancelSubscription $cancel,
    ) {}

    public function __invoke(Request $request, string $provider): Response
    {
        $chosen = BillingProvider::tryFrom($provider);

        if ($chosen === null || $chosen === BillingProvider::Manual) {
            return $this->done();
        }

        $gateway = $this->billing->for($chosen);

        if (! $gateway->isConfigured()) {
            // Somebody is posting to an endpoint for a provider we do not use.
            return $this->done();
        }

        try {
            $notice = $gateway->verify($request);
        } catch (Throwable $exception) {
            Log::warning('A subscription webhook could not be verified.', [
                'provider' => $chosen->value,
                'error' => $exception->getMessage(),
            ]);

            return $this->done();
        }

        if ($notice === null) {
            /*
             * Verification failed, which is the one case worth saying out
             * loud: either somebody is posting forged notices, or the webhook
             * secret here does not match the one at the provider — and the
             * second looks exactly like silence until an account lapses that
             * had been paying all along.
             */
            Log::warning('A subscription webhook failed verification and was ignored.', [
                'provider' => $chosen->value,
                'ip' => $request->ip(),
            ]);

            return $this->done();
        }

        if ($notice->kind === BillingEventKind::Ignored) {
            return $this->done();
        }

        $subscription = $this->subscriptionFor($notice, $request);

        if ($subscription === null) {
            Log::warning('A subscription webhook arrived for an unknown subscription.', [
                'provider' => $chosen->value,
                'kind' => $notice->kind->value,
                'subscription' => $notice->subscriptionId,
            ]);

            return $this->done();
        }

        $this->asTenant((int) $subscription->tenant_id, function () use ($notice, $subscription): void {
            // Re-read inside the tenant context: the row was found with
            // scoping off, and everything the actions touch expects a model
            // that belongs to the bound account.
            $scoped = Subscription::query()->find($subscription->getKey());

            if (! $scoped instanceof Subscription) {
                return;
            }

            match ($notice->kind) {
                BillingEventKind::Paid => $this->paid($scoped, $notice),
                BillingEventKind::PaymentFailed => $this->failure->handle($scoped, $notice->reason),
                BillingEventKind::Cancelled => $this->cancelled($scoped),
                BillingEventKind::Ignored => null,
            };
        });

        return $this->done();
    }

    /**
     * Money arrived.
     */
    private function paid(Subscription $subscription, BillingNotice $notice): void
    {
        /*
         * The first invoice of a new subscription is paid seconds after
         * checkout, and the browser coming back has usually started the
         * subscription already. Renewing on top of that would hand the
         * account a second period it has not paid for, so a period that still
         * has time left and no failure behind it is left alone.
         */
        if ($subscription->payment_failed_at === null && $subscription->isCurrent()) {
            return;
        }

        $this->renew->handle($subscription, $notice->amountCents);
    }

    /**
     * They cancelled at the provider rather than here.
     *
     * Only the record is updated: calling the gateway back to cancel
     * something it has just told us it cancelled is how two systems end up
     * talking each other in circles.
     */
    private function cancelled(Subscription $subscription): void
    {
        if ($subscription->cancelled_at !== null) {
            return;
        }

        $this->cancel->handle($subscription, 'Cancelled at the payment provider.');
    }

    /**
     * Whose subscription this is.
     *
     * By the provider's subscription id first, which is what every renewal
     * carries. Failing that, by the account reference we attached when
     * checkout started — the first webhook of a brand new subscription can
     * overtake the browser, and arriving before the id has been stored is
     * the one moment the id cannot be looked up.
     */
    private function subscriptionFor(BillingNotice $notice, Request $request): ?Subscription
    {
        if ($notice->subscriptionId !== null && $notice->subscriptionId !== '') {
            $found = Subscription::query()
                ->acrossTenants()
                ->where('provider_subscription_id', $notice->subscriptionId)
                ->first();

            if ($found instanceof Subscription) {
                return $found;
            }
        }

        $uuid = $this->tenantReference($request);

        if ($uuid === null) {
            return null;
        }

        $tenant = app(TenantContext::class)->withoutScoping(
            static fn (): ?Tenant => Tenant::query()->where('uuid', $uuid)->first()
        );

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return Subscription::query()
            ->acrossTenants()
            ->where('tenant_id', $tenant->getKey())
            ->first();
    }

    /**
     * The account reference we asked the provider to carry.
     *
     * Stripe hands it back as `client_reference_id` or in metadata, PayPal as
     * `custom_id`. Reading the raw body rather than asking the gateway keeps
     * both shapes in one place: it is the same string under four names.
     */
    private function tenantReference(Request $request): ?string
    {
        $object = $request->json('data.object');
        $resource = $request->json('resource');

        foreach ([$object, $resource] as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $uuid = $candidate['client_reference_id']
                ?? $candidate['custom_id']
                ?? $candidate['metadata']['tenant_uuid']
                ?? $candidate['subscription_details']['metadata']['tenant_uuid']
                ?? null;

            if (is_string($uuid) && $uuid !== '') {
                return $uuid;
            }
        }

        return null;
    }

    private function done(): Response
    {
        return response()->noContent();
    }
}
