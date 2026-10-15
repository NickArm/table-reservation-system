<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The separate, unticked box at checkout (SPEC §8, §28.4).
 *
 * Stored on the reservation because that is where it is given. When the guest
 * registry arrives it moves up to the guest, but consent is never asked for
 * and then thrown away — a checkbox that goes nowhere is worse than no
 * checkbox at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->boolean('marketing_consent')->default(false)->after('locale');
            $table->datetime('marketing_consent_at')->nullable()->after('marketing_consent');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropColumn(['marketing_consent', 'marketing_consent_at']);
        });
    }
};
