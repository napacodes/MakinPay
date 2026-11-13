<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Append-only audit trail of a bill payment: every move of `status` or
 * `dispatch_state`, who made it and why, written in the same database
 * transaction as the change it records.
 *
 * `external_event_id` lets a provider webhook be applied at most once per
 * payment: a replayed delivery hits the unique key instead of a second
 * settlement. `payload` never holds the token.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bill_payment_events')) {
            return;
        }

        Schema::create('bill_payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_payment_id')->constrained('bill_payments')->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('from_dispatch_state', 32)->nullable();
            $table->string('to_dispatch_state', 32);
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('note', 500)->nullable();
            $table->string('external_event_id', 191)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['bill_payment_id', 'external_event_id']);
            $table->index(['bill_payment_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_payment_events');
    }
};
