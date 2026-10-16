<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Enums\ApiTokenMode;
use App\Models\ApiToken;
use Illuminate\Support\Facades\DB;

/**
 * Hands an account a token, or replaces the one it has (SPEC §15.2).
 *
 * Replacing is deliberately destructive: the old row is deleted rather than
 * marked revoked, so the moment the owner presses the button the previous
 * secret matches nothing. A grace period would be kinder to the integration
 * and useless against the reason anyone presses it — a token that has leaked
 * has to stop working now, not in an hour.
 *
 * The log rows that token produced survive it. Their api_token_id goes null
 * and the account's usage history stays whole.
 */
final readonly class IssueApiToken
{
    /**
     * The new token's plain value is returned here and nowhere else. Store it
     * or show it immediately; afterwards only the owner's own Developer page
     * can reveal it, by decrypting the column.
     */
    public function handle(ApiTokenMode $mode): ApiToken
    {
        return DB::transaction(function () use ($mode): ApiToken {
            ApiToken::query()->where('mode', $mode)->delete();

            $token = new ApiToken;

            $token->mode = $mode;
            $token->forceFill(ApiToken::mint($mode));
            $token->save();

            return $token;
        });
    }

    /**
     * The account's token for a mode, created on first ask.
     *
     * The Developer page calls this rather than creating tokens itself, so an
     * account that has never opened the page still has something to show the
     * first time it does.
     */
    public function existing(ApiTokenMode $mode): ApiToken
    {
        $token = ApiToken::query()->usable()->where('mode', $mode)->first();

        return $token ?? $this->handle($mode);
    }
}
