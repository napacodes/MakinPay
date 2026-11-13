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
        Schema::create('merchant_subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('merchant_subscription_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('reference', 60)->unique();
            $table->string('transaction_id')->nullable()->index();
            $table->string('status', 20)->default('pending');
            $table->decimal('amount', 24, 8);
            $table->string('currency_code', 10);
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->timestamp('due_at');
            $table->timestamp('checkout_expires_at')->nullable();
            $table->text('payment_url')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['merchant_subscription_id', 'sequence'], 'merchant_subscription_invoice_sequence_unique');
            $table->index(['merchant_subscription_id', 'status'], 'merchant_subscription_invoice_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_subscription_invoices');
    }
};
