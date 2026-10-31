<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Gateway;
use App\Enums\GatewayMode;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PaymentGatewaySettingFactory;
use Illuminate\Contracts\Encryption\DecryptException;
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
            //
            // 🔒 Read through readCredentials(), never through the attribute
            // directly — see the note there.
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
        /*
         * 🔒 Forget an unreadable original before writing over it.
         *
         * Eloquent decides whether an attribute changed by casting the
         * original and comparing — which for an encrypted cast means
         * decrypting it. On the one save that matters here, that original is
         * precisely the thing that cannot be decrypted, so `save()` threw
         * from inside the framework at the exact moment somebody was
         * replacing the key. Dropping the original makes the new value
         * unambiguously dirty, and a null original is never cast at all.
         */
        if ($this->credentialsAreUnreadable()) {
            $this->original['credentials'] = null;
        }

        $this->credentials = array_filter(
            $credentials,
            static fn (?string $value): bool => filled($value),
        );
    }

    /**
     * The stored keys, or nothing if they can no longer be read.
     *
     * 🔒 The encrypted cast throws `DecryptException` when APP_KEY is not the
     * one the row was written with — a rotated key, a database copied between
     * environments, a `key:generate` run after the first save. Every caller
     * here was reading the attribute directly, so the Payments screen died
     * with a stack trace and an owner had no way back in: the one page where
     * they could replace the unreadable keys was the page that would not
     * load.
     *
     * Unreadable is therefore treated as absent. That is honest — a key we
     * cannot decrypt is a key we cannot use — and it leaves the screen
     * working, which is the only route to fixing it.
     *
     * @return array<string, string>
     */
    public function readCredentials(): array
    {
        try {
            $credentials = $this->credentials;
        } catch (DecryptException) {
            return [];
        }

        return is_array($credentials) ? $credentials : [];
    }

    /**
     * Whether there is something stored that cannot be read.
     *
     * Different from having no keys at all, and the difference is the whole
     * message: "add your keys" is wrong advice for somebody whose keys are
     * sitting right there, encrypted with a key that no longer exists.
     */
    public function credentialsAreUnreadable(): bool
    {
        if (blank($this->getRawOriginal('credentials'))) {
            return false;
        }

        try {
            $this->credentials;
        } catch (DecryptException) {
            return true;
        }

        return false;
    }

    public function credential(string $key): ?string
    {
        $value = $this->readCredentials()[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function hasCredentials(): bool
    {
        return filled($this->readCredentials());
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
