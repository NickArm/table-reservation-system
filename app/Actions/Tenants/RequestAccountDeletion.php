<?php

declare(strict_types=1);

namespace App\Actions\Tenants;

use App\Actions\Platform\RecordAuditEntry;
use App\Billing\BillingManager;
use App\Enums\AuditAction;
use App\Enums\BillingProvider;
use App\Enums\TenantStatus;
use App\Models\Reservation;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * An owner asks for their account to be deleted (SPEC §29).
 *
 * Nothing is destroyed here. The request is recorded, the subscription is
 * stopped so that nothing is charged again, and a date thirty days out is set
 * for the actual purge — which protects against a regretted click and leaves
 * room for "I did not mean that" to reach support (§29.3).
 *
 * The one hard rule is the blocker. An account with upcoming reservations
 * that guests have already paid for cannot be deleted, because the venue
 * would disappear and leave fourteen tourists holding bookings that exist
 * nowhere. It is the same reasoning as closures (§24.5): the system never
 * cancels a paid booking by itself, and it does not let one be orphaned
 * either.
 */
final readonly class RequestAccountDeletion
{
    public function __construct(
        private TenantContext $context,
        private BillingManager $billing,
        private RecordAuditEntry $audit,
    ) {}

    /**
     * How many paid bookings stand in the way.
     *
     * Checked-in and completed bookings do not count — they have happened.
     * Only what is still ahead matters, which is also the only thing a guest
     * could turn up for.
     */
    public function blockers(): int
    {
        return Reservation::query()->stillToCome()->count();
    }

    public function handle(Tenant $tenant, User $actor, ?string $ip = null): Tenant
    {
        $blockers = $this->context->asTenant($tenant, fn (): int => $this->blockers());

        if ($blockers > 0) {
            throw new RuntimeException(
                'There are '.$blockers.' upcoming reservations on this account. '
                .'Cancel them — and refund your guests — or wait until they have '
                .'passed.'
            );
        }

        return DB::transaction(function () use ($tenant, $actor, $ip): Tenant {
            $purgeAt = now()->addDays((int) config('tenancy.purge_after_days'));

            /*
             * Stopped at the provider first. An account that is going away
             * must not be charged again, and the order matters: marking it
             * deleted and then failing to stop the charge is the outcome
             * worth going out of the way to avoid.
             */
            $this->stopPaying($tenant);

            $tenant->forceFill([
                'deletion_requested_at' => now(),
                'purge_at' => $purgeAt,

                /*
                 * Suspended, not expired. The booking page stops and the
                 * dashboard goes read-only, and — this is the point —
                 * `SyncTenantStatus` never undoes a suspension, so a stray
                 * webhook from the provider cannot bring a deleted account
                 * back to life (§4.3).
                 */
                'status' => TenantStatus::Suspended,
            ])->save();

            $this->audit->handle(
                AuditAction::AccountDeletionRequested,
                $tenant->name.' asked to be deleted — purge on '.$purgeAt->format('j M Y').'.',
                actor: $actor,
                tenant: $tenant,
                context: ['purge_at' => $purgeAt->toIso8601String(), 'ip' => $ip],
            );

            return $tenant;
        });
    }

    /**
     * Support puts it back.
     *
     * Deliberately not reachable from the dashboard: the dashboard is
     * read-only by then, and thirty days of quiet is the protection. Somebody
     * who changes their mind writes to us, which is what the email tells them
     * to do — and this is what we run.
     */
    public function cancel(Tenant $tenant, User $actor, string $reason): Tenant
    {
        return DB::transaction(function () use ($tenant, $actor, $reason): Tenant {
            $tenant->forceFill([
                'deletion_requested_at' => null,
                'purge_at' => null,
            ])->save();

            $this->audit->handle(
                AuditAction::AccountDeletionCancelled,
                $tenant->name.' is no longer being deleted.',
                actor: $actor,
                tenant: $tenant,
                reason: $reason,
            );

            return $tenant;
        });
    }

    /**
     * Cancel the subscription at the provider, so nothing is charged again.
     *
     * A failure here does not stop the deletion. The alternative is an owner
     * who cannot leave because our provider is having an afternoon, and the
     * worse of the two outcomes — a charge after deletion — is recoverable by
     * a refund, while a trapped account is not recoverable by anything.
     */
    private function stopPaying(Tenant $tenant): void
    {
        $subscription = Subscription::query()
            ->acrossTenants()
            ->where('tenant_id', $tenant->getKey())
            ->first();

        if (! $subscription instanceof Subscription) {
            return;
        }

        $provider = $subscription->provider;
        $reference = (string) $subscription->provider_subscription_id;

        if (! $provider instanceof BillingProvider
            || $provider === BillingProvider::Manual
            || $reference === ''
        ) {
            return;
        }

        try {
            $this->billing->for($provider)->cancel($reference);
        } catch (Throwable $exception) {
            Log::error('Could not cancel a subscription while deleting an account.', [
                'tenant' => $tenant->slug,
                'provider' => $provider->value,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
