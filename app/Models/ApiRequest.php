<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ApiRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One line in the public API's log (SPEC §15.2.1).
 *
 * It answers the two questions an owner's developer asks when an integration
 * misbehaves: is it reaching us at all, and what are we saying back. Without
 * it the only available answer is "works here".
 *
 * Only calls whose token resolved to an account are recorded, including the
 * ones that were then refused — a revoked token, a mode without the feature,
 * a rate limit. A bearer string that matches nothing at all is answered 401
 * and written nowhere: there is no account it could be shown to, and a table
 * of anonymous refusals is a place for junk to accumulate, not evidence.
 *
 * Nothing here is personal. No addresses, no names, no query strings: an
 * endpoint, a status, a duration and a time.
 */
class ApiRequest extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ApiRequestFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'api_token_id',
        'method',
        'endpoint',
        'status',
        'duration_ms',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<ApiRequest>  $query
     * @return Builder<ApiRequest>
     */
    public function scopeSince(Builder $query, string $moment): Builder
    {
        return $query->where('occurred_at', '>=', $moment);
    }

    public function wasRejected(): bool
    {
        return $this->status >= 400;
    }
}
