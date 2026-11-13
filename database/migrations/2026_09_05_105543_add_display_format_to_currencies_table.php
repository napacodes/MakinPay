<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give every currency its own symbol placement and digit grouping.
 *
 * Money::format() has always emitted symbol + number_format(), which is right
 * for USD and wrong for the two cases this platform actually ships to: a
 * suffix symbol (USDT, and most European conventions) and lakh/crore grouping
 * (BDT, INR, PKR render 2,32,000, not 232,000).
 *
 * Both columns default to the current behaviour, so an installation that
 * upgrades and changes nothing renders byte for byte what it rendered before.
 * The only thing that can move the output is an administrator editing a
 * currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('currencies')) {
            return;
        }

        Schema::table('currencies', function (Blueprint $table): void {
            if (! Schema::hasColumn('currencies', 'symbol_position')) {
                $table->string('symbol_position', 10)
                    ->default('prefix')
                    ->after('symbol')
                    ->comment('Where the symbol sits relative to the amount: prefix or suffix.');
            }

            if (! Schema::hasColumn('currencies', 'number_grouping')) {
                $table->string('number_grouping', 10)
                    ->default('standard')
                    ->after('symbol')
                    ->comment('Digit grouping: standard (thousands) or indian (lakh/crore).');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('currencies')) {
            return;
        }

        Schema::table('currencies', function (Blueprint $table): void {
            foreach (['symbol_position', 'number_grouping'] as $column) {
                if (Schema::hasColumn('currencies', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
