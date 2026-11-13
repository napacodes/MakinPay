<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record the limits the payment processor itself imposes.
 *
 * `min_deposit` / `max_deposit` are the platform's own policy, denominated in
 * the base currency — the same threshold whatever currency the method settles
 * in, which is how AML and KYC caps are written.
 *
 * A processor's limit is a different thing entirely. CoinGate will not open an
 * invoice below EUR 10; a card scheme has its own ceiling. Those are stated in
 * the processor's currency and have nothing to do with the platform's policy.
 * Overloading one column with both meanings is what produced the misconfigured
 * limits this work already had to repair, so these get their own columns.
 *
 * Both nullable: null means the processor states no limit, which is different
 * from a limit of zero.
 */
return new class extends Migration
{
    private const TABLES = ['deposit_methods', 'withdraw_methods'];

    private const COLUMNS = ['provider_min_amount', 'provider_max_amount'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $missing = array_values(array_filter(
                self::COLUMNS,
                fn (string $column): bool => ! Schema::hasColumn($table, $column),
            ));

            if ($missing === []) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table, $missing): void {
                $after = Schema::hasColumn($table, 'conversion_rate') ? 'conversion_rate' : null;

                foreach ($missing as $column) {
                    $definition = $blueprint->decimal($column, 24, 8)->nullable();

                    if ($after !== null) {
                        $definition->after($after);
                        $after = $column;
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = array_values(array_filter(
                self::COLUMNS,
                fn (string $column): bool => Schema::hasColumn($table, $column),
            ));

            if ($existing === []) {
                continue;
            }

            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($existing));
        }
    }
};
