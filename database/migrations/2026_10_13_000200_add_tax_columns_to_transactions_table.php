<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The tax a transaction charged (GF6), kept apart from its fee so operators
 * can report and remit it.
 *
 *   wallet_tax     the tax in the payable currency, inside payable_amount:
 *                  payable = net + wallet_fee + wallet_tax.
 *   base_tax       the same tax in the site currency, for reporting, as
 *                  base_fee is to wallet_fee.
 *   tax_breakdown  one entry per rule that applied: id, name, percent, fixed,
 *                  base and amount, in the payable currency.
 *
 * All nullable: a row written before GF6, or by a flow with no tax, has none,
 * and reads as no tax everywhere. Guarded so a second run is harmless.
 */
return new class extends Migration
{
    private const TABLE = 'transactions';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::TABLE, 'wallet_tax')) {
                $table->decimal('wallet_tax', 24, 8)->nullable()->after('wallet_fee');
            }

            if (! Schema::hasColumn(self::TABLE, 'base_tax')) {
                $table->decimal('base_tax', 24, 8)->nullable()->after('wallet_tax');
            }

            if (! Schema::hasColumn(self::TABLE, 'tax_breakdown')) {
                $table->json('tax_breakdown')->nullable()->after('base_tax');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            foreach (['tax_breakdown', 'base_tax', 'wallet_tax'] as $column) {
                if (Schema::hasColumn(self::TABLE, $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
