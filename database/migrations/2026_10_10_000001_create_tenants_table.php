<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->string('name');

            // Appears in /reservation/{slug}. Soft deletes keep the row, which
            // keeps the slug taken for good: an old link must never resolve to
            // a different venue (SPEC §29.3).
            $table->string('slug')->unique();

            $table->string('status')->default('trialing')->index();
            $table->string('timezone')->default('Europe/Athens');
            $table->string('locale', 5)->default('en');
            $table->string('currency', 3)->default('EUR');

            $table->timestamp('trial_ends_at')->nullable();

            // Which version of the platform terms this account accepted, and
            // from where (SPEC §12.2).
            $table->string('accepted_terms_version')->nullable();
            $table->timestamp('accepted_terms_at')->nullable();
            $table->string('accepted_terms_ip', 45)->nullable();

            // Deletion is a two-step: requested now, purged after 30 days
            // (SPEC §29.3).
            $table->timestamp('deletion_requested_at')->nullable();
            $table->timestamp('purge_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
