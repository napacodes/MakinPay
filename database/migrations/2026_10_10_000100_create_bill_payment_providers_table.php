<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Who pays the biller for a bill payment.
 *
 * GF2 ships one driver, Manual: an operator pays at the biller and records the
 * receipt. API providers arrive later and keep their credentials in the
 * matching `plugins` row, the way mobile recharge and virtual-card providers
 * do, which is what `plugin_id` is for.
 *
 * Fees are exact decimals. `fee_fixed` is an amount in `fee_currency` and only
 * applies to billers priced in that currency, so a fixed fee is never
 * converted at whatever today's rate happens to be.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bill_payment_providers')) {
            return;
        }

        Schema::create('bill_payment_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plugin_id')->nullable()->constrained('plugins')->nullOnDelete();
            $table->string('code', 64)->unique();
            $table->string('name', 120);
            $table->string('driver', 32)->index();
            $table->string('logo')->nullable();
            $table->string('description', 500)->nullable();
            $table->boolean('status')->default(false)->index();
            $table->boolean('is_system')->default(false);
            $table->decimal('fee_percent', 8, 4)->default(0);
            $table->decimal('fee_fixed', 24, 8)->default(0);
            $table->string('fee_currency', 10)->nullable();
            $table->string('processing_note', 255)->nullable();
            $table->json('config')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_payment_providers');
    }
};
