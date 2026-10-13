<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Gateway;
use App\Enums\GatewayMode;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PaymentGatewaySettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One venue's keys for one gateway (SPEC §7.4).
 *
 * `credentials` is encrypted at rest and hidden from every serialisation.
 * Nothing in the application should ever need the raw value except the
 * gateway class that is about to make a call with it.
 */
#[Fillable(['gateway', 'mode', 'statement_descriptor', 'is_active'])]
#[Hidden(['credentials'])]
class PaymentGatewaySetting extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PaymentGatewaySettingFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gateway' => Gateway::class,
            'mode' => GatewayMode::class,

            // Laravel's encrypted cast uses APP_KEY. A separate key for
            // gateway secrets is on the deployment checklist (SPEC §21.4).
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
            'last_tested_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  array<string, string>  $credentials
     */
    public function setCredentials(array $credentials): void
    {
        $this->credentials = array_filter(
            $credentials,
            static fn (?string $value): bool => filled($value),
        );
    }

    public function credential(string $key): ?string
    {
        $value = ($this->credentials ?? [])[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function hasCredentials(): bool
    {
        return filled($this->credentials);
    }

    /**
     * "sk_live_••••4242" — enough for the owner to recognise which key is in
     * there, useless to anybody who should not have it.
     */
    public function mask(string $key): ?string
    {
        $value = $this->credential($key);

        if ($value === null) {
            return null;
        }

        return strlen($value) <= 8
            ? str_repeat('•', strlen($value))
            : substr($value, 0, 7).str_repeat('•', 4).substr($value, -4);
    }

    public function isUsable(): bool
    {
        return $this->is_active
            && (! $this->gateway->needsCredentials() || $this->hasCredentials());
    }

    public function isTestMode(): bool
    {
        return $this->mode === GatewayMode::Test;
    }
}
