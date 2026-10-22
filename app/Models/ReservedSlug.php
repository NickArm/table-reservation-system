<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A slug that belonged to a deleted account (SPEC §29.3).
 *
 * Not tenant-scoped, and deliberately never deleted: the whole point is that
 * the name stays taken after the account it belonged to has gone.
 */
class ReservedSlug extends Model
{
    public static function isTaken(string $slug): bool
    {
        return self::query()->where('slug', $slug)->exists();
    }

    public static function reserve(string $slug, ?string $was = null): void
    {
        if (self::isTaken($slug)) {
            return;
        }

        /*
         * `forceCreate` because nothing here is fillable, on purpose: a row
         * in this table is written by exactly one action and is never meant
         * to arrive from a request. Strict mode refuses to drop attributes
         * quietly, so `create()` would throw rather than half-write — which
         * is how this was found.
         */
        self::forceCreate([
            'slug' => $slug,
            'was' => $was,
            'reserved_at' => now(),
        ]);
    }
}
