<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // --- Identity -------------------------------------------------
            $table->string('name');
            $table->text('description')->nullable();

            // --- Location (SPEC §33.1) ------------------------------------
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('region')->nullable();
            $table->string('country', 2)->default('GR');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // --- Contact --------------------------------------------------
            $table->string('phone')->nullable();
            $table->string('public_email')->nullable();
            $table->string('website_url')->nullable();
            $table->json('social')->nullable();

            // --- Category -------------------------------------------------
            $table->string('venue_category')->nullable();
            $table->string('cuisine')->nullable();
            $table->string('price_range', 5)->nullable();

            // --- Branding (SPEC §5.5) -------------------------------------
            $table->string('logo_path')->nullable();
            $table->string('hero_path')->nullable();
            $table->string('accent_color', 7)->default('#0E6E6E');

            // Scrim over the hero image. Mandatory for legibility, adjustable
            // by the owner with a live preview.
            $table->unsignedTinyInteger('hero_overlay')->default(40);

            // --- Search visibility (SPEC §33.2) ---------------------------
            $table->boolean('is_indexable')->default(true);
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 320)->nullable();

            // --- Operations -----------------------------------------------
            $table->string('timezone')->default('Europe/Athens');
            $table->json('settings')->nullable();

            $table->timestamps();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venues');
    }
};
