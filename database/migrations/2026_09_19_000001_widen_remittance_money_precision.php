<?php

use App\Support\SchemaColumnDefault;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bring the remittance module onto the ledger's precision.
 *
 * Every money column here was decimal(15,2) — two decimals baked into the
 * schema — while the rest of the platform moved to decimal(24,8) and reads its
 * precision from `currencies.decimal_places`. Remittance is the one module
 * where that is not a rounding nicety: its corridors are fiat-to-fiat, and the
 * corridors an operator actually opens include the three-decimal Gulf dinars
 * (BHD, JOD, KWD, OMR, TND) and the zero-decimal destinations (JPY, KRW, UGX,
 * VND, XOF). A KWD payout quoted at two decimals silently drops its third
 * digit — a thousandth of a dinar per transfer, on the leg the beneficiary
 * receives — and the quote can no longer be reconciled against the payout the
 * provider actually made.
 *
 * Widening only: 2 decimals fit inside 8, so no stored value changes and the
 * rollback is the reverse declaration rather than a data migration. The
 * exchange-rate columns are left alone; they already carry their own scale.
 *
 * Nullability and defaults are read back off the live schema for each column
 * rather than restated here, so this migration cannot itself drop a modifier —
 * the same pattern as `widen_currency_rate_and_fee_precision`.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const MONEY = [
        'remittance_quotes'    => ['send_amount', 'fee_amount', 'total_pay', 'receive_amount'],
        'remittance_transfers' => ['send_amount', 'fee_amount', 'total_paid', 'receive_amount'],
        'remittance_corridors' => ['fixed_fee', 'min_amount', 'max_amount'],
    ];

    public function up(): void
    {
        $this->migrateMoneyColumns(24, 8);
    }

    public function down(): void
    {
        $this->migrateMoneyColumns(15, 2);
    }

    private function migrateMoneyColumns(int $total, int $places): void
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

                if (strtolower((string) $current['type']) === "decimal({$total},{$places})") {
                    continue;
                }

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

    private function changeColumn(string $table, string $column, int $total, int $places, bool $nullable, mixed $default): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($column, $total, $places, $nullable, $default): void {
            $definition = $blueprint->decimal($column, $total, $places)->nullable($nullable);

            $existingDefault = SchemaColumnDefault::normalize($default);

            if ($existingDefault !== null) {
                $definition->default($existingDefault);
            }

            $definition->change();
        });
    }
};
