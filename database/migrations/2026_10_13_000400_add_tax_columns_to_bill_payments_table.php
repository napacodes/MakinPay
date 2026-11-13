<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The tax a bill payment carries (GF6), in the wallet's currency and inside
 * total_amount: total_amount = amount + fee + tax_amount. A refund already
 * gives back total_amount, so the tax goes back with the rest.
 *
 * Nullable: a payment from before GF6, or with no rule that applies, has
 * none. Guarded so a second run is harmless.
 */
return new class extends Migration
{
    private const TABLE = 'bill_payments';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::TABLE, 'tax_amount')) {
                $table->decimal('tax_amount', 24, 8)->nullable()->after('fee');
            }

            if (! Schema::hasColumn(self::TABLE, 'tax_breakdown')) {
                $table->json('tax_breakdown')->nullable()->after('tax_amount');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            foreach (['tax_breakdown', 'tax_amount'] as $column) {
                if (Schema::hasColumn(self::TABLE, $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
