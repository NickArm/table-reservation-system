<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Platform staff. Not a membership of any account, so it lives
            // here rather than on the pivot.
            $table->boolean('is_super_admin')->default(false)->index();

            $table->string('locale', 5)->default('en');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['is_super_admin', 'locale']);
        });
    }
};
