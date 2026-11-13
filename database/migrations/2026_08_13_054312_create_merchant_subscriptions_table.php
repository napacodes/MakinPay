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
        Schema::create('merchant_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 20);
            $table->string('external_reference', 100);
            $table->string('status', 20)->default('pending');
            $table->string('billing_cycle', 20);
            $table->decimal('amount', 24, 8);
            $table->string('currency_code', 10);
            $table->string('customer_name', 100)->nullable();
            $table->string('customer_email', 150);
            $table->string('description', 255)->nullable();
            $table->string('success_redirect', 255);
            $table->string('cancel_redirect', 255);
            $table->string('ipn_url', 255);
            $table->timestamp('starts_at');
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('next_billing_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['merchant_id', 'environment', 'external_reference'], 'merchant_subscription_reference_unique');
            $table->index(['merchant_id', 'environment', 'status'], 'merchant_subscription_owner_status_idx');
            $table->index(['status', 'next_billing_at'], 'merchant_subscription_due_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_subscriptions');
    }
};
