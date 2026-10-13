<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Catches the payments the webhooks missed (SPEC §24.2).
 *
 * Webhooks are lost: our downtime, a queue worker that died, a signature that
 * did not match, a timeout. The guest paid and has no booking, and nobody
 * finds out until they turn up at the door.
 *
 * So once an hour we ask the gateway directly about everything still pending.
 * Unglamorous, independent of webhooks entirely, and the reason a venue can
 * trust the system with money.
 */
final readonly class ReconcilePayments
{
    public function __construct(
        private TenantContext $context,
        private SettlePayment $settle,
    ) {}

    /**
     * @return array{checked: int, settled: int, failed: int}
     */
    public function handle(int $hours = 24): array
    {
        $checked = 0;
        $settled = 0;
        $failed = 0;

        $pending = $this->context->withoutScoping(
            fn () => Payment::query()
                ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::RefundPending->value])
                ->where('created_at', '>=', now()->subHours($hours))
                ->get()
        );

        foreach ($pending as $payment) {
            $tenant = Tenant::query()->find($payment->tenant_id);

            if ($tenant === null) {
                continue;
            }

            $checked++;

            try {
                $before = $payment->status;

                $this->context->asTenant($tenant, function () use ($payment): void {
                    $fresh = Payment::query()->find($payment->id);

                    if ($fresh !== null) {
                        $this->settle->handle($fresh);
                    }
                });

                $after = Payment::query()->withoutGlobalScopes()->find($payment->id)?->status;

                if ($after === PaymentStatus::Paid && $before !== PaymentStatus::Paid) {
                    $settled++;
                }
            } catch (Throwable $exception) {
                $failed++;

                Log::error('Could not reconcile a payment.', [
                    'payment' => $payment->id,
                    'tenant' => $payment->tenant_id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return ['checked' => $checked, 'settled' => $settled, 'failed' => $failed];
    }
}
