<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "1st Row", "2nd Row", "By the window" — how guests actually think about
 * where they will sit (SPEC §5.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('areas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_type_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // Overrides of the booking type, so the front row can sell for the
            // whole day while the second row turns over twice (SPEC §6.2.1).
            $table->string('occupancy')->nullable();
            $table->unsignedSmallInteger('max_stay_minutes')->nullable();
            $table->unsignedSmallInteger('turnover_buffer_minutes')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['booking_type_id', 'sort_order']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('areas');
    }
};
