<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bookable thing: table 12, set A4, sunbed B7 (SPEC §5.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('area_id')->constrained()->cascadeOnDelete();

            $table->string('label');

            $table->boolean('is_composite')->default(false);
            $table->json('components')->nullable();

            $table->unsignedTinyInteger('capacity_min')->default(1);
            $table->unsignedTinyInteger('capacity_max')->default(2);

            $table->unsignedInteger('base_price_cents')->default(0);
            $table->string('price_basis')->default('per_resource');

            // Switched by hand by the owner. Closing stops new bookings only;
            // bookings already taken stay valid (SPEC §5.4).
            $table->string('online_status')->default('open');

            // Retired rather than deleted, so past bookings keep their history.
            $table->boolean('is_active')->default(true);

            // Anything the venue wants to record about a spot: shade, power,
            // "nearest the steps". Deliberately not called `attributes` —
            // inside an Eloquent model that name resolves to the raw attribute
            // array, which silently copies the whole row.
            $table->json('meta')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            // --- Floor plan geometry (SPEC §30.4) ------------------------
            //
            // Normalised 0–1, as a share of the plan's own dimensions. Absolute
            // pixels would scatter every table the moment someone uploads a
            // drawing of a different size.
            $table->decimal('pos_x', 8, 6)->nullable();
            $table->decimal('pos_y', 8, 6)->nullable();
            $table->decimal('width', 8, 6)->nullable();
            $table->decimal('height', 8, 6)->nullable();
            $table->unsignedSmallInteger('rotation')->nullable();
            $table->string('shape')->nullable();

            $table->timestamps();

            $table->unique(['booking_type_id', 'label']);
            $table->index(['booking_type_id', 'area_id', 'online_status', 'is_active'], 'resources_lookup_index');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resources');
    }
};
