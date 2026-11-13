<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Append-only audit trail of a mass payment: every move of the batch status,
 * and the row-level moves worth keeping (a failure, a review flag, an admin
 * action), who made each and why.
 *
 * `row_id` is null for a batch-level event. It is a plain column rather than
 * a foreign key so that the trail is never rewritten by a delete elsewhere;
 * the model refuses updates and deletes outright.
 *
 * No unique key: nothing external is replayed into this table, and the
 * exactly-once guarantees live on the batch and row tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mass_payment_events')) {
            return;
        }

        Schema::create('mass_payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mass_payment_batch_id')->constrained('mass_payment_batches')->cascadeOnDelete();
            $table->unsignedBigInteger('row_id')->nullable();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('note', 500)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['mass_payment_batch_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mass_payment_events');
    }
};
