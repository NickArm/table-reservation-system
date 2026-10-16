<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApiTokenMode;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ApiTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One account's bearer token (SPEC §15.2).
 *
 * `token` is encrypted and hidden from every serialisation. It leaves the
 * application in exactly one place — the owner's own Developer page — and
 * nowhere else: not in an API response, not in a log line, not in an error.
 */
#[Fillable(['mode'])]
#[Hidden(['token', 'token_hash'])]
class ApiToken extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ApiTokenFactory> */
    use HasFactory;

    /**
     * Characters a token is built from.
     *
     * No look-alikes. An owner reading a token off one screen and typing it
     * into another should not have to decide between 0 and O, or 1 and l —
     * and the support message that follows a wrong guess costs more than the
     * four characters are worth.
     */
    private const ALPHABET = 'abcdefghijkmnpqrstuvwxyz23456789';

    private const LENGTH = 40;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => ApiTokenMode::class,
            'token' => 'encrypted',
            'last_used_at' => 'immutable_datetime',
            'exposed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * A fresh secret, and the three columns derived from it.
     *
     * @return array{token: string, token_hash: string, prefix: string}
     */
    public static function mint(ApiTokenMode $mode): array
    {
        $body = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $body .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        $token = $mode->tokenPrefix().$body;

        return [
            'token' => $token,
            'token_hash' => self::hash($token),
            'prefix' => $mode->tokenPrefix().Str::substr($body, 0, 4),
        ];
    }

    /**
     * SHA-256, not bcrypt.
     *
     * A password hash is deliberately slow, which is right for a password a
     * human chose and wrong for a 40-character random string checked on every
     * API call: there is nothing to brute-force, and a slow hash here would
     * only make the rate limit arrive sooner.
     */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @param  Builder<ApiToken>  $query
     * @return Builder<ApiToken>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * Seen on a call that carried a browser Origin, so it is readable by
     * anyone who opens the developer tools on that site (SPEC §15.2.1).
     */
    public function isExposed(): bool
    {
        return $this->exposed_at !== null;
    }

    /**
     * What the Developer page shows before the owner asks to reveal it.
     */
    public function masked(): string
    {
        return $this->prefix.str_repeat('•', 12);
    }
}
