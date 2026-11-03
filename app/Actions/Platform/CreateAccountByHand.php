<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Actions\Subscriptions\StartSubscription;
use App\Actions\Tenants\RegisterVenueAccount;
use App\Enums\AuditAction;
use App\Enums\BillingProvider;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Opens an account the way most of them will actually be opened: a telephone
 * call, a plan agreed, and an address to write to (SPEC §4.1).
 *
 * The same three rows as a self-service sign-up — user, tenant, venue — built
 * by the same action, so there is one definition of what a working account
 * is and a hand-made one cannot drift into being subtly different from a
 * registered one.
 *
 * Two things are deliberately different.
 *
 * **There is no trial.** Somebody who has agreed a price on the telephone has
 * no use for fifteen days of evaluation, and leaving them on trial would mean
 * the nightly sweep expiring an account that is paid for.
 *
 * **The provider is Manual**, which is the whole point: nothing will ever
 * charge this account, because a person sends it an invoice. That one value
 * is what keeps the dunning emails away from somebody whose card we have
 * never had — see SendDunningNotices, which only ever looks at subscriptions
 * with a failed payment on them.
 */
final readonly class CreateAccountByHand
{
    public function __construct(
        private RegisterVenueAccount $register,
        private StartSubscription $subscriptions,
        private InviteUser $invitations,
        private RecordAuditEntry $audit,
        private TenantContext $context,
    ) {}

    /**
     * @return array{tenant: Tenant, owner: User, invitation: string}
     */
    public function handle(
        string $venueName,
        string $ownerName,
        string $ownerEmail,
        Plan $plan,
        ?User $actor = null,
        bool $sendInvitation = true,
    ): array {
        $result = DB::transaction(function () use (
            $venueName,
            $ownerName,
            $ownerEmail,
            $plan,
        ): array {
            /*
             * A password nobody knows, not even us. The row needs a hash —
             * the column is not nullable and a nullable password is a hole
             * that every later `attempt()` has to remember to close — so it
             * gets one that no human will ever type. The way in is the
             * invitation.
             */
            $owner = $this->register->handle(
                $ownerName,
                $ownerEmail,
                Str::password(40),
                $venueName,
            );

            $tenant = $owner->tenants()->firstOrFail();

            /*
             * Onto the plan immediately, paid period running from today. The
             * trial the registration action set is left behind by this: the
             * status is derived from the subscription from here on, and
             * SyncTenantStatus stops looking at trial_ends_at the moment a
             * subscription exists.
             *
             * Bound to the new tenant for the duration, because the
             * subscription and the event it writes are both tenant-scoped
             * and `RegisterVenueAccount` leaves no tenant in context when it
             * returns. Every other caller of StartSubscription is already
             * inside a request that resolved one; this one is the first that
             * has to bind its own.
             */
            $this->context->asTenant(
                $tenant,
                fn () => $this->subscriptions->handle($tenant, $plan, BillingProvider::Manual),
            );

            return ['tenant' => $tenant->refresh(), 'owner' => $owner];
        });

        $tenant = $result['tenant'];
        $owner = $result['owner'];

        /*
         * Outside the transaction, both of them. An invitation sends an
         * email, and an email inside a transaction is an email that goes out
         * and then gets rolled back — the account would not exist and the
         * link would be dead on arrival.
         */
        $invitation = $this->invitations->handle($owner, $tenant, $sendInvitation);

        $this->audit->handle(
            AuditAction::TenantCreated,
            sprintf(
                'Opened %s on %s, %s %s, billed by hand. Invited %s.',
                $tenant->name,
                $plan->name,
                Money::format((int) $plan->price_cents),
                $plan->billing_period->per(),
                $owner->email,
            ),
            $actor,
            $tenant,
        );

        return ['tenant' => $tenant, 'owner' => $owner, 'invitation' => $invitation];
    }
}
