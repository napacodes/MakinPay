<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One company a customer can pay: a power utility, a TV operator, a school.
 *
 * Every amount here is in the biller's own `currency`. Fee columns left null
 * inherit the provider's rule. `customer_number_pattern` is a regex body with
 * no delimiters or anchors; the module wraps and anchors it itself.
 *
 * New billers are created switched off, so a half-configured biller never
 * reaches a customer. `external_code` is the catalogue key an API provider
 * syncs against, unique per provider.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('billers')) {
            return;
        }

        Schema::create('billers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_category_id')->constrained('bill_categories')->restrictOnDelete();
            $table->foreignId('bill_payment_provider_id')->constrained('bill_payment_providers')->restrictOnDelete();
            $table->string('name', 120);
            $table->char('country', 2);
            $table->string('currency', 10);
            $table->string('logo')->nullable();
            $table->string('description', 500)->nullable();

            $table->string('customer_number_label', 80)->default('Account number');
            $table->string('customer_number_example', 64)->nullable();
            $table->string('customer_number_help', 255)->nullable();
            $table->string('customer_number_pattern', 255)->nullable();

            $table->string('amount_type', 16)->default('open');
            $table->decimal('min_amount', 24, 8)->nullable();
            $table->decimal('max_amount', 24, 8)->nullable();

            $table->decimal('fee_percent', 8, 4)->nullable();
            $table->decimal('fee_fixed', 24, 8)->nullable();

            $table->string('token_label', 80)->nullable();
            $table->string('processing_note', 255)->nullable();
            $table->text('instructions')->nullable();

            $table->string('external_code', 128)->nullable();
            $table->json('external_meta')->nullable();

            $table->boolean('status')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['country', 'bill_category_id', 'status'], 'billers_country_category_status_index');
            $table->unique(['bill_payment_provider_id', 'external_code'], 'billers_provider_external_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billers');
    }
};
