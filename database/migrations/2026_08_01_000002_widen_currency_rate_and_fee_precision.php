<?php

use App\Support\SchemaColumnDefault;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen exchange rates and the money columns feeding them.
 *
 * `currencies.exchange_rate` was a MySQL FLOAT — single precision, roughly 7
 * significant digits — which cannot hold a rate like 0.0000094118 reliably.
 * Rates get decimal(28,12) to match DecimalMoney::RATE_SCALE; every other
 * money column here gets decimal(24,8) to match the ledger.
 *
 * `exchange_rate` had also drifted to NULLABLE DEFAULT 0 despite the original
 * create migration declaring `->default(1)` NOT NULL — the classic symptom of a
 * ->change() that failed to restate its modifiers. A default of 0 is a
 * landmine: a zero rate does not truncate a conversion, it destroys it. The
 * original intent is restored here, with NULLs backfilled to 1 first.
 *
 * Existing nullability and defaults are read back off the live schema for every
 * other column rather than hardcoded, so this migration cannot itself drop a
 * modifier.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const MONEY = [
        'currency_roles'   => ['min_limit', 'max_limit', 'fee'],
        'deposit_methods'  => ['min_deposit', 'max_deposit', 'conversion_rate', 'charge', 'user_charge', 'merchant_charge', 'agent_charge'],
        'withdraw_methods' => ['min_withdraw', 'max_withdraw', 'conversion_rate', 'charge', 'user_charge', 'merchant_charge', 'agent_charge'],
    ];

    public function up(): void
    {
        // 18 fractional digits: rates need relative precision, and against a
        // low-denomination base such as IDR a BTC rate is around 5.9e-10.
        $this->migrateRateColumn(36, 18);
        $this->migrateMoneyColumns(24, 8);
    }

    /**
     * Narrowing loses precision that cannot be recovered, but must not throw —
     * a rollback that dies half way is worse than a lossy one.
     */
    public function down(): void
    {
        $this->migrateMoneyColumns(null, null);
        $this->migrateRateColumn(null, null);
    }

    private function migrateRateColumn(?int $total, ?int $places): void
    {
        if (! Schema::hasTable('currencies') || ! Schema::hasColumn('currencies', 'exchange_rate')) {
            return;
        }

        if ($total === null) {
            $this->changeColumn('currencies', 'exchange_rate', null, null, nullable: true, default: 0);

            return;
        }

        if ($this->columnType('currencies', 'exchange_rate') === "decimal({$total},{$places})") {
            return;
        }

        // A NULL rate is unusable, so it can only be replaced. Rates of 0 are
        // left untouched: inventing a number there would write unverifiable
        // financial config. `money:audit-suspicious` reports them instead.
        DB::table('currencies')->whereNull('exchange_rate')->update(['exchange_rate' => 1]);

        $this->changeColumn('currencies', 'exchange_rate', $total, $places, nullable: false, default: 1);
    }

    private function migrateMoneyColumns(?int $total, ?int $places): void
    {
        foreach (self::MONEY as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = collect(Schema::getColumns($table))->keyBy('name');

            foreach ($columns as $column) {
                $current = $existing[$column] ?? null;

                if ($current === null) {
                    continue;
                }

                if ($total !== null && strtolower((string) $current['type']) === "decimal({$total},{$places})") {
                    continue;
                }

                // Preserve whatever nullability and default the column already
                // carries; only the storage width changes.
                $this->changeColumn(
                    $table,
                    $column,
                    $total,
                    $places,
                    nullable: (bool) $current['nullable'],
                    default: $current['default'],
                );
            }
        }
    }

    private function changeColumn(string $table, string $column, ?int $total, ?int $places, bool $nullable, mixed $default): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($column, $total, $places, $nullable, $default): void {
            $definition = $total === null
                ? $blueprint->double($column)
                : $blueprint->decimal($column, $total, $places);

            $definition->nullable($nullable);

            $existingDefault = SchemaColumnDefault::normalize($default);

            if ($existingDefault !== null) {
                $definition->default($existingDefault);
            }

            $definition->change();
        });
    }

    private function columnType(string $table, string $column): string
    {
        $found = collect(Schema::getColumns($table))->firstWhere('name', $column);

        return strtolower((string) ($found['type'] ?? ''));
    }
};
