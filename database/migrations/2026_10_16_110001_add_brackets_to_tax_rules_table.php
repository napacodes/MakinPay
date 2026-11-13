<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Amount brackets on a tax rule (GF6): "up to 10 pays 0.20, up to 50 pays
 * 0.50, up to 100 pays 1.00". Each bracket is {up_to, percent, fixed}, the
 * limit and the fixed part in the site currency, the last limit null for
 * "and above". The transaction amount picks the bracket.
 *
 * Null keeps the rule's single percent and fixed amount, so every existing
 * rule charges exactly what it charged before.
 *
 * Guarded so a second run is harmless.
 */
return new class extends Migration
{
    private const TABLE = 'tax_rules';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || Schema::hasColumn(self::TABLE, 'brackets')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->json('brackets')->nullable()->after('fixed_amount');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && Schema::hasColumn(self::TABLE, 'brackets')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropColumn('brackets');
            });
        }
    }
};
