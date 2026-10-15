<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The thing the whole product exists for (SPEC §14).
 *
 * Created here, in the availability milestone, because availability means
 * nothing without something to collide with. The booking flow that fills it
 * arrives in M4–M5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // Human-readable, said out loud over the phone: DEMO-7K4F2.
            $table->string('code', 20)->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();

            // 🔒 Nullable on purpose (SPEC §32.3). A ticket to a DJ night
            // claims a seat in a count, not a particular sunbed. Adding this
            // later would mean altering a live reservations table.
            $table->foreignId('resource_id')->nullable()->constrained()->nullOnDelete();

            // What was booked, as it was at the time: the voucher has to stay
            // correct even after the owner rearranges a set (SPEC §14).
            $table->json('resource_snapshot')->nullable();

            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone')->nullable();
            $table->string('locale', 5)->default('en');

            // 🔒 A quantity, never derived from the resource's capacity
            // (SPEC §32.3) — a ticket order is "4 people", not "table for 4".
            $table->unsignedSmallInteger('party_size')->default(1);

            // UTC, always. The venue's timezone turns them into something a
            // person reads, and only at that moment (SPEC §3.5).
            $table->datetime('starts_at');
            $table->datetime('ends_at');

            $table->string('status')->default('pending_payment');
            $table->text('notes')->nullable();

            $table->unsignedInteger('amount_cents')->default(0);
            $table->string('deposit_label_snapshot')->nullable();
            $table->string('payment_status')->default('not_required');

            $table->string('channel')->default('website');
            $table->string('referrer_domain')->nullable();

            $table->unsignedBigInteger('terms_version_id')->nullable();
            $table->datetime('terms_accepted_at')->nullable();
            $table->string('terms_accepted_ip', 45)->nullable();

            $table->datetime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason')->nullable();

            // 🔒 Idempotent check-in (SPEC §32.3): setting a timestamp that is
            // already set changes nothing, which is what an offline scanner
            // replaying its queue needs.
            $table->datetime('checked_in_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The overlap check runs on every availability lookup, so this
            // index is the one that decides whether the page feels instant.
            $table->index(['resource_id', 'starts_at', 'ends_at', 'status']);
            $table->index(['tenant_id', 'starts_at']);
            $table->index(['booking_type_id', 'starts_at']);
            $table->index(['tenant_id', 'channel', 'created_at']);
            $table->index(['tenant_id', 'customer_email', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
