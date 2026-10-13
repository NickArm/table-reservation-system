<?php

declare(strict_types=1);

namespace App\Actions\Reservations;

use App\Models\ReservationHold;
use App\Tenancy\TenantContext;

/**
 * Deletes holds nobody paid for (SPEC §6.4).
 *
 * Runs across every venue, so it bypasses tenant scoping on purpose — one of
 * the few places that may.
 *
 * It is a tidy-up, not a safeguard: every availability check already ignores
 * expired holds, because shared hosting may only run cron every five minutes
 * and a sunbed must not look taken in the meantime (SPEC §21.2).
 */
final readonly class ReleaseExpiredHolds
{
    public function __construct(private TenantContext $context) {}

    public function handle(): int
    {
        return $this->context->withoutScoping(
            static fn (): int => ReservationHold::query()
                ->where('expires_at', '<=', now())
                ->delete()
        );
    }
}
