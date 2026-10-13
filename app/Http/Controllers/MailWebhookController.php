<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * What the mail provider tells us about a message after it left (SPEC §10.3).
 *
 * This exists for one scenario. A guest mistypes their address, the
 * confirmation bounces, they arrive expecting a table and the venue has never
 * heard of them. The booking is real and the only fix is a phone call — which
 * can only happen if somebody knows. So a bounce is written against the
 * booking and the dashboard shows it in red.
 *
 * Nothing is trusted from the body beyond the message id, which is matched
 * against ids we recorded ourselves as the messages went out. An unknown id
 * is answered with 200 and ignored: a provider that gets an error retries for
 * days, and there is nothing here worth retrying.
 */
final class MailWebhookController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(Request $request, string $secret): JsonResponse
    {
        // config() rather than Config::string(): the value is null until the
        // env var is set, and the typed reader refuses a null outright.
        $expected = (string) config('services.brevo.webhook_secret');

        // No secret configured means the endpoint is shut, not open.
        if ($expected === '' || ! hash_equals($expected, $secret)) {
            return new JsonResponse(['message' => 'Not found'], 404);
        }

        $payload = $request->json()->all();

        // Some providers post one event, some post a batch.
        $events = array_is_list($payload) ? $payload : [$payload];

        $recorded = 0;

        foreach ($events as $event) {
            if (is_array($event) && $this->record($event)) {
                $recorded++;
            }
        }

        return new JsonResponse(['recorded' => $recorded]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function record(array $event): bool
    {
        $messageId = $event['message-id'] ?? $event['message_id'] ?? null;

        if (! is_string($messageId) || $messageId === '') {
            return false;
        }

        $status = $this->statusFor((string) ($event['event'] ?? ''));

        if ($status === null) {
            return false;
        }

        $updated = $this->context->withoutScoping(
            fn (): int => Reservation::query()
                ->acrossTenants()
                ->where('voucher_message_id', trim($messageId, '<>'))
                ->update(['voucher_delivery' => $status])
        );

        if ($updated > 0 && $status !== 'delivered') {
            Log::warning('A booking confirmation did not reach the guest.', [
                'status' => $status,
                'event' => $event['event'] ?? null,
                'reason' => $event['reason'] ?? null,
            ]);
        }

        return $updated > 0;
    }

    /**
     * Provider vocabularies differ; what the venue needs to know does not.
     * Either the message arrived, or it did not and somebody should call.
     */
    private function statusFor(string $event): ?string
    {
        return match (strtolower($event)) {
            'delivered' => 'delivered',
            'hard_bounce', 'hardbounce', 'soft_bounce', 'softbounce',
            'blocked', 'invalid_email', 'error', 'bounce' => 'bounced',
            'spam', 'complaint', 'unsubscribed' => 'complained',
            // "request" means accepted for delivery and "deferred" means try
            // again later. Neither is news, and recording either as success
            // would hide a bounce that follows.
            default => null,
        };
    }
}
