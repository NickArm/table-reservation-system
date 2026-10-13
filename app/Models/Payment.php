<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Gateway;
use App\Enums\GatewayMode;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Money;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to pay for one reservation (SPEC §8.3, §24.2).
 */
#[Fillable([
    'gateway', 'mode', 'session_id', 'gateway_payment_id',
    'amount_cents', 'currency', 'status', 'paid_at', 'payload',
])]
class Payment extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gateway' => Gateway::class,
            'mode' => GatewayMode::class,
            'status' => PaymentStatus::class,
            'paid_at' => 'immutable_datetime',
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function isPaid(): bool
    {
        return $this->status === PaymentStatus::Paid;
    }

    /**
     * What is still refundable. Checked here rather than trusted from a form,
     * because several partial refunds must never add up to more than the
     * charge (SPEC §8.3).
     */
    public function refundableCents(): int
    {
        return max(0, (int) $this->amount_cents - (int) $this->refunded_amount_cents);
    }

    public function isFullyRefunded(): bool
    {
        return $this->refundableCents() === 0 && (int) $this->refunded_amount_cents > 0;
    }

    /**
     * A refund made soon after the charge goes through as a reversal: the
     * original charge disappears from the guest's statement and the gateway
     * keeps no fee. Worth telling an owner, because it rewards dealing with a
     * cancellation now rather than next week (SPEC §8.3).
     */
    public function qualifiesAsReversal(): bool
    {
        return $this->paid_at !== null && $this->paid_at->isAfter(now()->subHours(12));
    }

    public function describe(): string
    {
        return Money::format((int) $this->amount_cents).' · '.$this->gateway->label();
    }

    /**
     * Keeps the gateway's own words without keeping anything that looks like
     * card data (SPEC §30.2).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function scrub(array $payload): array
    {
        $forbidden = [
            'card', 'number', 'cvc', 'cvv', 'exp_month', 'exp_year',
            'client_secret', 'secret', 'api_key', 'authorization',
        ];

        $clean = [];

        foreach ($payload as $key => $value) {
            if (in_array(mb_strtolower((string) $key), $forbidden, true)) {
                continue;
            }

            $clean[$key] = is_array($value) ? self::scrub($value) : $value;
        }

        return $clean;
    }
}
