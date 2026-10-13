<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Payments\SettlePayment;
use App\Enums\Gateway;
use App\Models\Payment;
use App\Models\Tenant;
use App\Payments\GatewayManager;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What the gateways tell us, when they can reach us (SPEC §7.4, §24.2).
 *
 * One endpoint per venue — /webhooks/stripe/{uuid} — because each venue has
 * its own keys and therefore its own signing secret. Without the uuid in the
 * path there would be no way to know which secret to verify against.
 *
 * Anyone can post JSON here, so nothing is believed until the signature
 * checks out. And even then the event only triggers a question to the
 * gateway: we act on what the gateway tells us when we ask, not on what
 * arrives at our door.
 */
final class WebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $gateway,
        string $tenantUuid,
        TenantContext $context,
        GatewayManager $gateways,
        SettlePayment $settle,
    ): JsonResponse {
        $type = Gateway::tryFrom($gateway);

        if ($type === null || ! $type->isOnline()) {
            return response()->json(['received' => true]);
        }

        $tenant = $context->withoutScoping(
            static fn (): ?Tenant => Tenant::query()->where('uuid', $tenantUuid)->first()
        );

        if ($tenant === null) {
            // Deliberately vague and deliberately 200-adjacent: a 404 here
            // would let anyone probe for valid venue ids.
            return response()->json(['received' => true], 404);
        }

        return $context->asTenant($tenant, function () use ($request, $type, $gateways, $settle, $tenant): JsonResponse {
            try {
                [$implementation, $setting] = $gateways->resolve($type);
            } catch (Throwable) {
                return response()->json(['received' => true]);
            }

            $event = $implementation->verifyWebhook($setting, $request);

            if ($event === null) {
                Log::warning('Rejected a webhook with a bad signature.', [
                    'gateway' => $type->value,
                    'tenant' => $tenant->id,
                ]);

                return response()->json(['error' => 'signature'], 400);
            }

            $sessionId = $implementation->sessionIdFromEvent($event);

            if ($sessionId === null) {
                // An event we do not act on. Acknowledged, so the gateway
                // stops retrying it.
                return response()->json(['received' => true]);
            }

            $payment = Payment::query()->where('session_id', $sessionId)->first();

            if ($payment === null) {
                Log::warning('A webhook referred to a payment we do not have.', [
                    'gateway' => $type->value,
                    'session' => $sessionId,
                    'tenant' => $tenant->id,
                ]);

                return response()->json(['received' => true]);
            }

            // Idempotent by construction: settling a paid payment again does
            // nothing, which is what makes a replayed webhook harmless.
            $settle->handle($payment);

            return response()->json(['received' => true]);
        });
    }
}
