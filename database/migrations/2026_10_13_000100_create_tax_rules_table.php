<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Tax and levy rules an admin defines (GF6): which transaction type a rule
 * taxes, for which customers (everyone, local, international or listed
 * countries, judged by the customer's own country against the home country),
 * a percent of the amount or of the fee and/or a fixed amount in the site
 * currency, and whether it is active.
 *
 * Every matching active rule applies and the taxes add up, each kept as its
 * own line. An installation with no rules (every upgrade) charges exactly what
 * it charged before.
 *
 * Guarded so a second run is harmless.
 */
return new class extends Migration
{
    private const TABLE = 'tax_rules';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('transaction_type', 32);
            $table->string('scope', 16)->default('all');
            $table->json('countries')->nullable();
            $table->decimal('percent', 9, 4)->default(0);
            $table->decimal('fixed_amount', 24, 8)->default(0);
            $table->string('base', 8)->default('amount');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['transaction_type', 'is_active'], 'tax_rules_type_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
