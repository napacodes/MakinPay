<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Close the NULL hole in `deposit_methods` / `withdraw_methods.conversion_rate`.
 *
 * The create migrations declared `->double('rate')` NOT NULL, so a freshly
 * migrated install has these columns NOT NULL. Long-lived installs do not: an
 * earlier ->change() dropped the modifier, and every widening since has read
 * the live nullability back and faithfully restated the drift — the same
 * failure `currencies.exchange_rate` was repaired for, left unrepaired here.
 *
 * A NULL rate carries no meaning the schema does not already have a value for.
 * Both models read the column as `?? '0'`, so nothing downstream can tell a
 * NULL from a zero — except the readers that go through the query builder,
 * where NULL casts to an empty string that bcmath cannot parse. That threw
 * `Money value must be numeric.` out of the dashboard's rate-health partial and
 * returned a 500 for the entire admin dashboard.
 *
 * NULLs are backfilled to 0, not to 1. Zero is what the accessors already
 * report and what the payment path already refuses to convert through; a 1
 * would silently price the method one-for-one with the base currency, which is
 * the exact "1000 USD buys 1000 BTC" configuration RateGuard exists to catch.
 * A method with live rates enabled is repriced by the next
 * `currency:refresh-rates` run regardless.
 */
return new class extends Migration
{
    private const TABLES = ['deposit_methods', 'withdraw_methods'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! $this->hasColumn($table)) {
                continue;
            }

            DB::table($table)->whereNull('conversion_rate')->update(['conversion_rate' => 0]);

            $this->apply($table, nullable: false);
        }
    }

    /**
     * Reopening the hole cannot fail — the backfilled zeros stay zeros, since
     * restoring them to NULL would only re-create the crash.
     */
    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if ($this->hasColumn($table)) {
                $this->apply($table, nullable: true);
            }
        }
    }

    private function apply(string $table, bool $nullable): void
    {
        $current = collect(Schema::getColumns($table))->firstWhere('name', 'conversion_rate');

        if ((bool) ($current['nullable'] ?? false) === $nullable) {
            return;
        }

        // Restate the storage width alongside the nullability: ->change()
        // regenerates the whole definition and drops whatever is left out.
        [$total, $places] = $this->precision((string) ($current['type'] ?? ''));

        Schema::table($table, function (Blueprint $blueprint) use ($total, $places, $nullable): void {
            $blueprint->decimal('conversion_rate', $total, $places)
                ->nullable($nullable)
                ->default(0)
                ->change();
        });
    }

    /**
     * The column's own precision, falling back to rate scale on a driver that
     * does not report a width (SQLite reports a bare "numeric" after a
     * ->change() has rebuilt the table).
     *
     * @return array{int, int}
     */
    private function precision(string $type): array
    {
        return preg_match('/\((\d+),(\d+)\)/', $type, $matches) === 1
            ? [(int) $matches[1], (int) $matches[2]]
            : [36, 18];
    }

    private function hasColumn(string $table): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, 'conversion_rate');
    }
};
