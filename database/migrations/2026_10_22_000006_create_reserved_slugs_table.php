<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slugs that may never be used again (SPEC §29.3).
 *
 * A deleted account's slug is reserved for ever, so that a link printed on a
 * sign, a flyer or somebody's Instagram never resolves to a **different**
 * venue. The alternative is a tourist following last year's QR code to a
 * stranger's booking page and paying them.
 *
 * It has to be its own table rather than a check against `tenants`, because
 * purging an account removes its row — `uniqueSlug()` already looks at
 * soft-deleted tenants, which covers the thirty days before the purge and
 * nothing after it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reserved_slugs', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();

            // What it was, for whoever reads this table in two years and
            // wonders why `crystal-seaside` is taken.
            $table->string('was')->nullable();

            $table->datetime('reserved_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserved_slugs');
    }
};
