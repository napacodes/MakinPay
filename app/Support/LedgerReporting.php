<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Read the ledger in the reporting currency.
 *
 * `transactions.amount` is not denominated in one unit. Flows that take a
 * customer-entered figure and convert it store a base-currency amount; flows
 * that already work in the wallet store a wallet-currency one. `currency` was
 * meant to say which, and for a while it did not: the deposit and withdraw
 * writer stamped the wallet's code on a base-currency amount, so a reader that
 * trusted the pair divided a figure that was already in base by the wallet's
 * rate. A $1,000 BTC deposit came back as $62,500,000.
 *
 * The FX snapshot settles it. `base_amount` / `base_fee` are unambiguous — they
 * are in `base_currency` and nothing else — so a report that has them needs no
 * rate at all. These expressions prefer the snapshot and fall back to the
 * legacy pair only for rows written before it existed, which is the one case
 * where there is nothing better to use.
 *
 * They alias back to `amount` / `currency`, so a caller substitutes them into
 * its select list and everything downstream keeps working unchanged.
 *
 * The same fallback applies when the snapshot columns are absent from the table
 * altogether. An installation whose files were updated without running the
 * migrations has the reading code but not the columns, and naming one in a
 * SELECT is a hard database error rather than a NULL — it took down both
 * dashboards with "Unknown column 'base_amount'". A schema that predates the
 * snapshot has no restated figure for any row, so reading the legacy pair is
 * both the only option and exactly what the row-level fallback would choose.
 */
class LedgerReporting
{
    /**
     * Ledger column => the FX snapshot column restating it in base currency.
     *
     * A whitelist rather than a derived name: these strings are interpolated
     * into raw SQL.
     *
     * @var array<string, string>
     */
    private const RESTATED = [
        'amount' => 'base_amount',
        'fee'    => 'base_fee',
    ];

    /**
     * Memoised `Schema::hasColumn` answers, keyed by column name.
     *
     * Every expression here is built per query, so an uncached lookup would put
     * an information_schema round trip in front of each one. The schema cannot
     * change within a request; a long-lived worker started before the migration
     * ran needs restarting, which a deployment does anyway.
     *
     * @var array<string, bool>
     */
    private static array $columnExists = [];

    /**
     * SQL for an amount expressed in the reporting currency.
     */
    public static function amount(string $column = 'amount'): string
    {
        $restated = self::restatedColumn($column);

        if (! self::ledgerHasColumn($restated)) {
            return $column;
        }

        return "CASE WHEN {$restated} IS NULL THEN {$column} ELSE {$restated} END";
    }

    /**
     * SQL for the currency {@see amount()} is denominated in.
     *
     * Must be selected alongside its amount and derived from the same column:
     * whether a row is restated is decided per row, and pairing a restated
     * amount with the legacy currency would convert it a second time.
     *
     * `base_currency` is the base as it stood when the row was written, which
     * is not necessarily today's. That is deliberate — a rebase leaves history
     * denominated in the old base, and converting it back through the old
     * base's current rate is exactly right.
     */
    public static function currency(string $column = 'amount'): string
    {
        $restated = self::restatedColumn($column);

        if (! self::ledgerHasColumn($restated) || ! self::ledgerHasColumn('base_currency')) {
            return 'currency';
        }

        return "CASE WHEN {$restated} IS NULL THEN currency ELSE base_currency END";
    }

    /**
     * Both halves as a select fragment, aliased to the legacy names.
     */
    public static function selectAs(string $column, string $amountAlias, string $currencyAlias = 'currency'): string
    {
        return self::amount($column).' as '.$amountAlias.', '.self::currency($column).' as '.$currencyAlias;
    }

    /**
     * Forget the memoised schema answers.
     *
     * For tests that add or drop the snapshot columns inside one process.
     */
    public static function flushSchemaCache(): void
    {
        self::$columnExists = [];
    }

    private static function ledgerHasColumn(string $column): bool
    {
        return self::$columnExists[$column] ??= Schema::hasTable('transactions')
            && Schema::hasColumn('transactions', $column);
    }

    private static function restatedColumn(string $column): string
    {
        return self::RESTATED[$column]
            ?? throw new InvalidArgumentException("No FX snapshot column is defined for [{$column}].");
    }
}
