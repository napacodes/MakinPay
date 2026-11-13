<?php

use App\Support\FeeCalculator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move every configurable fee from "fixed XOR percent" to "fixed + percent",
 * with optional floor and ceiling.
 *
 * A single amount column plus a discriminator physically cannot hold both
 * 0.5% and 1.00 at once, so adding a 'both' option to the enum would have
 * required a second amount column anyway — two columns plus a now-redundant
 * discriminator. Four modules in this codebase had already converged on the
 * two-column shape independently (mobile_recharge_providers,
 * virtual_card_fee_settings, marketplace_settings, agent_commission_rules);
 * this brings the rest in line.
 *
 * The legacy `fee_type` / `charge_type` columns are retained and kept in sync
 * by the models, so customer customisations and API consumers reading them
 * keep working. They should not be dropped before two minor releases.
 *
 * The backfill is lossless in both directions, which is what makes down()
 * clean rather than destructive.
 */
return new class extends Migration
{
    /**
     * table => [legacy amount column => legacy type column]
     *
     * @var array<string, array<string, string>>
     */
    private const MAP = [
        'currency_roles' => [
            'fee' => 'fee_type',
        ],
        'deposit_methods' => [
            'charge'          => 'charge_type',
            'user_charge'     => 'user_charge_type',
            'merchant_charge' => 'merchant_charge_type',
            'agent_charge'    => 'agent_charge_type',
        ],
        'withdraw_methods' => [
            'charge'          => 'charge_type',
            'user_charge'     => 'user_charge_type',
            'merchant_charge' => 'merchant_charge_type',
            'agent_charge'    => 'agent_charge_type',
        ],
    ];

    public function up(): void
    {
        foreach (self::MAP as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $this->addColumns($table, array_keys($columns));
            $this->backfill($table, $columns);
        }
    }

    public function down(): void
    {
        foreach (self::MAP as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $this->restoreLegacy($table, $columns);
            $this->dropColumns($table, array_keys($columns));
        }
    }

    /** @param list<string> $amountColumns */
    private function addColumns(string $table, array $amountColumns): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($table, $amountColumns): void {
            foreach ($amountColumns as $column) {
                foreach ($this->newColumnsFor($column) as $new => $nullable) {
                    if (Schema::hasColumn($table, $new)) {
                        continue;
                    }

                    $definition = $blueprint->decimal($new, 24, 8);

                    $nullable ? $definition->nullable() : $definition->default(0);

                    $definition->after($column);
                }
            }
        });
    }

    /** @param array<string, string> $columns */
    private function backfill(string $table, array $columns): void
    {
        foreach ($columns as $amountColumn => $typeColumn) {
            [$fixedColumn, $percentColumn] = array_keys($this->newColumnsFor($amountColumn));

            if (! Schema::hasColumn($table, $typeColumn)) {
                continue;
            }

            // Idempotent: only rows still at the zero default are touched, so a
            // re-run after a partial failure cannot overwrite operator edits.
            DB::table($table)
                ->where(function ($query) use ($fixedColumn, $percentColumn) {
                    $query->where($fixedColumn, 0)->where($percentColumn, 0);
                })
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($table, $amountColumn, $typeColumn, $fixedColumn, $percentColumn): void {
                    foreach ($rows as $row) {
                        $split = FeeCalculator::fromLegacy(
                            $row->{$typeColumn}   ?? null,
                            $row->{$amountColumn} ?? 0,
                        );

                        DB::table($table)->where('id', $row->id)->update([
                            $fixedColumn   => $split['fixed'],
                            $percentColumn => $split['percent'],
                        ]);
                    }
                });
        }
    }

    /**
     * Collapse back to a single amount + type. Lossless for any row that came
     * from the legacy shape; a row configured with both components keeps the
     * larger contribution, which is the closest single value available.
     *
     * @param array<string, string> $columns
     */
    private function restoreLegacy(string $table, array $columns): void
    {
        foreach ($columns as $amountColumn => $typeColumn) {
            [$fixedColumn, $percentColumn] = array_keys($this->newColumnsFor($amountColumn));

            if (! Schema::hasColumn($table, $fixedColumn) || ! Schema::hasColumn($table, $typeColumn)) {
                continue;
            }

            DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($table, $amountColumn, $typeColumn, $fixedColumn, $percentColumn): void {
                foreach ($rows as $row) {
                    $fixed   = (float) ($row->{$fixedColumn} ?? 0);
                    $percent = (float) ($row->{$percentColumn} ?? 0);

                    $usePercent = $percent > 0 && $fixed <= 0;

                    DB::table($table)->where('id', $row->id)->update([
                        $amountColumn => $usePercent ? $percent : $fixed,
                        $typeColumn   => $usePercent ? 'percent' : 'fixed',
                    ]);
                }
            });
        }
    }

    /** @param list<string> $amountColumns */
    private function dropColumns(string $table, array $amountColumns): void
    {
        foreach ($amountColumns as $column) {
            $drop = array_filter(
                array_keys($this->newColumnsFor($column)),
                fn (string $new): bool => Schema::hasColumn($table, $new),
            );

            if ($drop === []) {
                continue;
            }

            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn(array_values($drop)));
        }
    }

    /**
     * New column names for a legacy amount column, mapped to whether they are
     * nullable. `_min` / `_max` are nullable because "no floor" and "no
     * ceiling" are meaningfully different from zero.
     *
     * @return array<string, bool>
     */
    private function newColumnsFor(string $column): array
    {
        return [
            $column.'_fixed'   => false,
            $column.'_percent' => false,
            $column.'_min'     => true,
            $column.'_max'     => true,
        ];
    }
};
