<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each venue's own payment keys (SPEC §7.4).
 *
 * The money never passes through us: these are the venue's credentials, used
 * to charge the venue's guests into the venue's account. We only ever hold
 * them, which is why they are encrypted at rest and never returned by any
 * endpoint — masked or not at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('venue_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('gateway');
            $table->string('mode')->default('test');

            // Encrypted JSON. Never selected into a response, never logged,
            // and scrubbed before anything reaches Sentry (SPEC §30.2).
            $table->text('credentials')->nullable();

            // Shown on the guest's card statement, so they recognise the
            // charge and do not call it fraud.
            $table->string('statement_descriptor', 22)->nullable();

            $table->boolean('is_active')->default(false);

            $table->datetime('last_tested_at')->nullable();
            $table->string('last_test_result')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'gateway']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_settings');
    }
};
