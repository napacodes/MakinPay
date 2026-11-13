<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per webhook the platform owes a merchant.
 *
 * Until now a merchant IPN was a synchronous POST inside the request that
 * settled the payment: three tries on a timeout only, nothing on a 5xx, and
 * no record afterwards of whether the merchant ever heard. This table is that
 * record. The payload is stored as the exact bytes that are signed, so every
 * retry carries the same body and the same signature the merchant verifies.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('merchant_webhook_deliveries')) {
            return;
        }

        Schema::create('merchant_webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trx_id', 64)->nullable()->index();
            $table->string('event', 50);
            $table->string('environment', 20);
            $table->string('url', 500);
            $table->longText('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'created_at'], 'merchant_webhook_deliveries_merchant_created_idx');
            $table->index(['status', 'next_attempt_at'], 'merchant_webhook_deliveries_status_next_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_webhook_deliveries');
    }
};
