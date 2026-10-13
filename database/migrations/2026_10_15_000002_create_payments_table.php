<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One attempt to pay for one reservation (SPEC §14).
 *
 * Separate from the reservation because a booking can be paid once, refunded
 * in parts, and argued about months later — and because reconciliation needs
 * somewhere to look when a webhook never arrives (SPEC §24.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();

            $table->string('gateway');
            $table->string('mode')->default('test');

            // The checkout the guest was sent to, and the charge it produced.
            // Two different things: one exists before any money moves.
            $table->string('session_id')->nullable()->index();
            $table->string('gateway_payment_id')->nullable()->index();

            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('EUR');
            $table->string('status')->default('pending');

            // Cumulative, checked server side: a gateway allows several
            // partial refunds and their sum must never exceed the charge.
            $table->unsignedInteger('refunded_amount_cents')->default(0);
            $table->string('refund_status')->nullable();

            // The acquirer reference the guest's bank can trace — the only
            // useful answer to "where is my money?" (SPEC §8.3).
            $table->string('refund_reference')->nullable();
            $table->string('refund_failure_reason')->nullable();

            $table->timestamp('paid_at')->nullable();

            // Whatever the gateway last told us, kept for the arguments that
            // come months later. Scrubbed of anything resembling card data.
            $table->json('payload')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
