<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record when each exchange rate was last set, and by what.
 *
 * A rate with no age cannot be told apart from a rate that was never
 * configured. That is how an install ships with BTC sitting at exactly 1 and
 * silently converts 1000 USD into 1000 BTC — the value looks like any other
 * rate, because nothing records that no one ever set it.
 *
 * `remittance_quotes.rate_fetched_at` already establishes this pattern in the
 * codebase; this brings the currency table in line with it.
 *
 * Both columns are nullable, and NULL means "provenance unknown, this row
 * predates the column" — not "stale". Only a rate that has an age can be
 * judged on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('currencies')) {
            return;
        }

        Schema::table('currencies', function (Blueprint $table): void {
            if (! Schema::hasColumn('currencies', 'rate_updated_at')) {
                $table->timestamp('rate_updated_at')->nullable()->after('exchange_rate');
            }

            if (! Schema::hasColumn('currencies', 'rate_source')) {
                // manual | live | rebase | seed
                $table->string('rate_source', 20)->nullable()->after('rate_updated_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('currencies')) {
            return;
        }

        $columns = array_values(array_filter(
            ['rate_updated_at', 'rate_source'],
            fn (string $column): bool => Schema::hasColumn('currencies', $column),
        ));

        if ($columns === []) {
            return;
        }

        Schema::table('currencies', fn (Blueprint $table) => $table->dropColumn($columns));
    }
};
