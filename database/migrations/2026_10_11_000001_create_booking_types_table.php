<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a guest can book: "Beach Sunbeds", "Dining Room" (SPEC §5.1).
 *
 * Sits between the venue and its areas because the two are genuinely different
 * products — different hours, different way of booking, different prices, and
 * a beach that closes for the winter while the dining room carries on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('icon')->nullable();

            $table->string('resource_type')->default('table');
            $table->string('booking_mode')->default('slot');

            // --- Slot shape (SPEC §6.2) ----------------------------------
            $table->unsignedSmallInteger('slot_duration_minutes')->default(90);
            $table->unsignedSmallInteger('slot_interval_minutes')->default(30);
            $table->unsignedSmallInteger('turnover_buffer_minutes')->default(15);

            $table->time('arrival_window_start')->nullable();
            $table->time('arrival_window_end')->nullable();

            // --- How long the resource is held (SPEC §6.2.1) -------------
            $table->string('occupancy')->default('fixed_duration');
            $table->unsignedSmallInteger('max_stay_minutes')->nullable();

            // --- Timing rules (SPEC §6.3) --------------------------------
            $table->unsignedSmallInteger('min_lead_time_minutes')->default(60);
            $table->unsignedSmallInteger('max_advance_days')->default(60);
            $table->unsignedSmallInteger('hold_duration_minutes')->default(15);

            // --- Money (SPEC §7.3) ---------------------------------------
            $table->string('payment_requirement')->default('required');

            // Free text the owner writes, shown at checkout and on the
            // voucher: "Deducted from your final bill". What the money means
            // is their business, not ours.
            $table->string('deposit_label')->nullable();

            $table->unsignedInteger('default_price_cents')->default(0);
            $table->string('price_basis')->default('per_resource');

            $table->json('cancellation_policy')->nullable();

            $table->string('default_view')->default('list');

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['venue_id', 'slug']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_types');
    }
};
