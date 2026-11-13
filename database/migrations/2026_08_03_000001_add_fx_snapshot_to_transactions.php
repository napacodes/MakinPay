<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make every ledger entry a self-contained FX snapshot (IAS 21).
 *
 * The standard multi-currency ledger stores three things per entry: the amount
 * in the account's own currency, the rate applied at that moment, and the
 * equivalent in the reporting (base) currency. This table stored none of them
 * unambiguously — a single deposit row held three different units at once:
 *
 *     amount           20.00   base currency   (USD)
 *     fee               0.20   base currency   (USD)
 *     currency          EUR    <- labels `amount`, but `amount` is USD
 *     net_amount       18.40   account currency (EUR)
 *     payable_amount   18.58   account currency (EUR)
 *
 * and the 0.92 rate that produced 18.40 was never written anywhere. Once the
 * rate moved, the row could no longer be reconstructed or reconciled.
 *
 * `exchange_rate` is deliberately NULLABLE. NULL means "this row predates the
 * snapshot, the rate is genuinely unknown" — the historical rate cannot be
 * recovered, and defaulting it to 1 would write a financial fact we do not
 * have. `money:audit-precision` reports these instead.
 *
 * The legacy `currency` column is left untouched: customer code and the admin
 * UI read it. It stays ambiguous by design until the new columns have covered
 * two minor releases.
 */
return new class extends Migration
{
    /**
     * column => [type, args]
     *
     * @var array<string, array{string, array<int, int>}>
     */
    private const COLUMNS = [
        // 1 unit of base currency buys this many units of the account currency.
        'exchange_rate' => ['decimal', [36, 18]],
        // The reporting side. `base_currency` is stored per row because the
        // platform's base currency can be rebased later — without it, every
        // historical base_amount silently changes meaning.
        'base_amount'   => ['decimal', [24, 8]],
        'base_fee'      => ['decimal', [24, 8]],
        'base_currency' => ['string', [10]],
        // The account side, at the wallet currency's own precision.
        'wallet_amount' => ['decimal', [24, 8]],
        'wallet_fee'    => ['decimal', [24, 8]],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('transactions')) {
            return;
        }

        $missing = array_filter(
            self::COLUMNS,
            fn (array $spec, string $column): bool => ! Schema::hasColumn('transactions', $column),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($missing === []) {
            return;
        }

        // Adds are compiled into a single ALTER, unlike change() which rebuilds
        // the table once per column. Safe to do in one closure on a hot table.
        Schema::table('transactions', function (Blueprint $table) use ($missing): void {
            $after = 'payable_currency';

            foreach ($missing as $column => [$type, $args]) {
                $table->{$type}($column, ...$args)->nullable()->after($after);

                $after = $column;
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('transactions')) {
            return;
        }

        $existing = array_values(array_filter(
            array_keys(self::COLUMNS),
            fn (string $column): bool => Schema::hasColumn('transactions', $column),
        ));

        if ($existing === []) {
            return;
        }

        Schema::table('transactions', fn (Blueprint $table) => $table->dropColumn($existing));
    }
};
