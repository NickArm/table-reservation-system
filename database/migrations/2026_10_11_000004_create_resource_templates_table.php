<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A saved shape for the thing being sold, so "2 sunbeds + 1 umbrella +
 * 1 side table at €25" is described once and stamped out twenty times
 * (SPEC §5.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // Sold as a set, or one by one. The owner decides — we do not
            // assume a sunbed is always part of a pair (SPEC §5.2).
            $table->boolean('is_composite')->default(false);

            // [{"label": "Sunbed", "qty": 2}, {"label": "Umbrella", "qty": 1}]
            $table->json('components')->nullable();

            $table->unsignedTinyInteger('capacity_min')->default(1);
            $table->unsignedTinyInteger('capacity_max')->default(2);

            $table->unsignedInteger('base_price_cents')->default(0);
            $table->string('price_basis')->default('per_resource');

            // Anything the venue wants to record about a spot: shade, power,
            // "nearest the steps". Deliberately not called `attributes` —
            // inside an Eloquent model that name resolves to the raw attribute
            // array, which silently copies the whole row.
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_templates');
    }
};
