<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an account is actually paying for (SPEC §4.3).
 *
 * One row per account at a time: a venue has one subscription, and changing
 * plan changes this row rather than adding another. The history of what
 * happened to it lives in `subscription_events`.
 *
 * `tenants.status` stays the thing the rest of the application reads, because
 * every screen and both middlewares already do. It is a cache of what this
 * row means, written in exactly one place — see App\Actions\Subscriptions.
 * Two sources of truth about whether a venue may take bookings is the bug
 * that locks out a paying customer on a Saturday.
 *
 * This is *our* money, not the venue's. A venue's own gateway credentials,
 * for charging its own guests, are a different table and a different account
 * entirely (SPEC §7.4) — the two must never be confused, which is why the
 * provider columns here are named for the platform.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();

            // Genuinely tenant-owned, unlike plans: one account's
            // subscription is nobody else's business.
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Kept when a plan is retired, so the history still says what the
            // account was on.
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();

            // Mirrors TenantStatus, minus the states that are not about a
            // subscription at all.
            $table->string('status', 20)->default('trialing');

            $table->datetime('current_period_start')->nullable();
            $table->datetime('current_period_end')->nullable();

            // Set when the owner cancels: they keep what they paid for until
            // the period ends, and nothing renews after it.
            $table->boolean('cancel_at_period_end')->default(false);
            $table->datetime('cancelled_at')->nullable();

            // When a failed payment started the dunning clock, so the grace
            // period is measured from the failure rather than from whenever
            // the sweep happened to notice.
            $table->datetime('payment_failed_at')->nullable();

            // 'stripe', 'paypal' or 'manual'. Manual is a real case: a
            // deposit into the bank, recorded by the super admin (SPEC §4.1).
            $table->string('provider', 20)->default('manual');
            $table->string('provider_subscription_id')->nullable();
            $table->string('provider_customer_id')->nullable();

            $table->unsignedInteger('price_cents')->default(0);
            $table->string('currency', 3)->default('EUR');

            $table->timestamps();

            // One per account. A second row would immediately raise the
            // question of which one counts.
            $table->unique('tenant_id');

            // The sweep's own query: everything whose period has run out.
            $table->index(['status', 'current_period_end']);

            // A webhook arrives with the provider's id and nothing else.
            $table->index('provider_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
