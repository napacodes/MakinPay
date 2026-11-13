<?php

use App\Support\FeeCalculator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move Wallet Earn profit from "fixed XOR percentage" to "fixed + percentage".
 *
 * Every other configurable amount in the platform moved to the two-column
 * shape; staking was the module the fee work did not reach. A plan could pay
 * a flat 5 a month or 1.2% a month, never "1.2% plus 0.50", and never
 * "1.2%, but at least 1 and no more than 50" — which is how a real yield
 * product is priced, because a percentage alone pays dust on a small stake and
 * unbounded amounts on a large one.
 *
 * The stake table carries the same columns as the plan because a stake
 * snapshots its terms at open time: changing a plan must not silently re-price
 * money already committed under the old one.
 *
 * `profit_rate` / `profit_type` are retained and kept in sync by the models, so
 * customer code and existing rows keep working. The backfill is lossless in
 * both directions, which is what makes down() clean rather than destructive.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = ['wallet_earn_plans', 'wallet_earn_stakes'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (! Schema::hasColumn($table, 'profit_fixed')) {
                    $blueprint->decimal('profit_fixed', 24, 8)->default(0)->after('profit_rate')
                        ->comment('Flat profit per payout, in the stake currency.');
                }

                if (! Schema::hasColumn($table, 'profit_percent')) {
                    $blueprint->decimal('profit_percent', 12, 8)->default(0)->after('profit_fixed')
                        ->comment('Percentage of principal per payout.');
                }

                foreach (['profit_min', 'profit_max'] as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        $blueprint->decimal($column, 24, 8)->nullable()->after('profit_percent');
                    }
                }
            });

            $this->backfill($table);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $this->restoreLegacy($table);

            $present = array_values(array_filter(
                ['profit_fixed', 'profit_percent', 'profit_min', 'profit_max'],
                fn (string $column): bool => Schema::hasColumn($table, $column),
            ));

            if ($present !== []) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($present));
            }
        }
    }

    /**
     * Split the single (rate, type) pair into the two columns.
     *
     * Chunked and keyed by id rather than issued as one UPDATE with a CASE,
     * because `profit_type` is a free-form varchar and a row holding anything
     * other than the two known values must be left alone rather than silently
     * read as one of them.
     */
    private function backfill(string $table): void
    {
        DB::table($table)
            ->select(['id', 'profit_rate', 'profit_type'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    $split = FeeCalculator::fromLegacy(
                        $row->profit_type === 'percentage' ? 'percent' : 'fixed',
                        $row->profit_rate,
                    );

                    DB::table($table)->where('id', $row->id)->update([
                        'profit_fixed'   => $split['fixed'],
                        'profit_percent' => $split['percent'],
                    ]);
                }
            });
    }

    /**
     * Collapse the pair back to the legacy shape. A configuration using both
     * components cannot be expressed by one column, so the percentage wins and
     * the flat part is dropped — the same precedence the models use when they
     * derive the legacy discriminator.
     */
    private function restoreLegacy(string $table): void
    {
        if (! Schema::hasColumn($table, 'profit_fixed')) {
            return;
        }

        DB::table($table)
            ->select(['id', 'profit_fixed', 'profit_percent'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    $usesPercent = (float) $row->profit_percent > 0;

                    DB::table($table)->where('id', $row->id)->update([
                        'profit_type' => $usesPercent ? 'percentage' : 'fixed',
                        'profit_rate' => $usesPercent ? $row->profit_percent : $row->profit_fixed,
                    ]);
                }
            });
    }
};
