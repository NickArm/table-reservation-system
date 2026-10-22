<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Actions\Subscriptions\SyncTenantStatus;
use App\Enums\AuditAction;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Switching an account off by hand, and back on (SPEC §2.1, §4.3).
 *
 * Different from expiry in a way that matters: expiry is what the nightly
 * sweep does when a subscription runs out, and the sweep can undo it the
 * moment a payment arrives. Suspension is a decision a person made — a
 * chargeback, an abuse report, a venue that has stopped answering — and
 * nothing automatic is allowed to reverse it. `SyncTenantStatus` checks for
 * it first and leaves it alone, which is the whole reason the state exists
 * separately.
 *
 * So the only way back is through here, by a person, and both directions are
 * written into the audit log. An account that came back on by itself would
 * mean nobody could say whether a suspension had held.
 */
final readonly class SuspendTenant
{
    public function __construct(
        private SyncTenantStatus $status,
        private RecordAuditEntry $audit,
    ) {}

    public function handle(Tenant $tenant, User $actor, string $reason): Tenant
    {
        return DB::transaction(function () use ($tenant, $actor, $reason): Tenant {
            $previous = $tenant->status;

            $tenant->forceFill(['status' => TenantStatus::Suspended])->save();

            $this->audit->handle(
                AuditAction::TenantSuspended,
                $tenant->name.' suspended.',
                actor: $actor,
                tenant: $tenant,
                reason: $reason,
                context: ['previous_status' => $previous->value],
            );

            return $tenant;
        });
    }

    /**
     * Back on, at whatever state the subscription actually justifies.
     *
     * Not back to what it was before: a venue suspended in March and restored
     * in August should not come back `active` because that is what it was in
     * March. The subscription decides, the same as everywhere else.
     */
    public function restore(Tenant $tenant, User $actor, ?string $reason = null): Tenant
    {
        return DB::transaction(function () use ($tenant, $actor, $reason): Tenant {
            // Set to something the sweep is allowed to move. Left as
            // Suspended, SyncTenantStatus would keep returning Suspended by
            // design and the restore would silently do nothing.
            $tenant->forceFill(['status' => TenantStatus::Expired])->save();

            $after = $this->status->handle($tenant);

            $this->audit->handle(
                AuditAction::TenantRestored,
                $tenant->name.' restored — now '.$after->label().'.',
                actor: $actor,
                tenant: $tenant,
                reason: $reason,
                context: ['status' => $after->value],
            );

            return $tenant;
        });
    }
}
