<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The packages a fixed-amount biller sells, such as a TV bouquet or a term's
 * school fee, each at its own price in the biller's currency.
 *
 * A payment keeps its own snapshot of the item's name and code, so removing an
 * item never rewrites history; `bill_payments.biller_item_id` is nulled.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('biller_items')) {
            return;
        }

        Schema::create('biller_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('biller_id')->constrained('billers')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('external_code', 128)->nullable();
            $table->decimal('amount', 24, 8);
            $table->boolean('status')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['biller_id', 'external_code'], 'biller_items_biller_external_unique');
            $table->index(['biller_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biller_items');
    }
};
