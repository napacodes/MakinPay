<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('remittance_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('remittance_transfers')->restrictOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('note')->nullable();
            $table->string('actor_type', 32)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('source', 80)->default('system');
            $table->string('external_event_id', 128)->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['transfer_id', 'occurred_at']);
            $table->index(['actor_type', 'actor_id']);
            $table->unique(['source', 'external_event_id'], 'remittance_external_event_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('remittance_status_events');
    }
};
