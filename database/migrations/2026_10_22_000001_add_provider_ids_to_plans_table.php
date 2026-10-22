<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each plan is called at Stripe and at PayPal (SPEC §4.3).
 *
 * A recurring subscription is set up against the provider's own idea of a
 * price, not against an amount we send: that is what lets the provider renew
 * it, retry a failed card and tell us about both. So each plan carries the
 * id of its counterpart at each provider.
 *
 * Filled in by hand from the provider dashboards rather than created through
 * their APIs. Creating prices programmatically means reconciling two sets of
 * records for ever — and a price is written once and then lives for years, so
 * the automation would save one minute and cost a permanent class of bug.
 *
 * Null means "not sellable through that provider", which is a real state: a
 * plan may exist at Stripe before anybody has set it up at PayPal, and the
 * billing page simply does not offer the provider that cannot sell it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->string('stripe_price_id')->nullable()->after('currency');
            $table->string('paypal_plan_id')->nullable()->after('stripe_price_id');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn(['stripe_price_id', 'paypal_plan_id']);
        });
    }
};
