<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an account can buy (SPEC §4.1).
 *
 * Not tenant-scoped: a plan belongs to the platform, and every account sees
 * the same public ones. The exception is a custom plan, which is how the super
 * admin gives one venue its own price and its own limits without that
 * appearing to anybody else.
 *
 * That column is called `custom_for_tenant_id` rather than `tenant_id`, and
 * the name is doing real work. Everywhere else in this schema `tenant_id`
 * means "this row belongs to that account and no query may cross the line" —
 * BelongsToTenant, the global scope, the isolation test that walks every
 * model. Here it means almost the opposite: the row belongs to the platform
 * and merely *mentions* an account. Reusing the name would either make the
 * isolation test demand a trait that would hide every public plan, or teach
 * everyone reading the schema that `tenant_id` sometimes means something
 * else. Neither is worth saving nine characters.
 *
 * `limits` and `features` are JSON rather than columns. Adding a feature
 * should be a line in an enum, not a migration and a deploy, and nothing ever
 * queries "which plans allow X" — enforcement always starts from one account's
 * own plan. The enums App\Enums\Feature and App\Enums\PlanLimit are the keys,
 * so a misspelling is a fatal error rather than a silently false feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();

            // Set only on a custom plan, which is then offered to that one
            // account and to nobody else.
            $table->foreignId('custom_for_tenant_id')->nullable()
                ->constrained('tenants')->cascadeOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            // 'monthly' or 'yearly'.
            $table->string('billing_period', 10)->default('monthly');

            // Integer cents, like every other amount here. A plan priced at
            // zero is the permanent free tier, should we decide to have one.
            $table->unsignedInteger('price_cents')->default(0);
            $table->string('currency', 3)->default('EUR');

            $table->unsignedSmallInteger('trial_days')->default(15);

            $table->json('limits')->nullable();
            $table->json('features')->nullable();

            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);

            // What the pricing page sorts by, so the order is a decision
            // rather than an accident of insertion.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['is_public', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
