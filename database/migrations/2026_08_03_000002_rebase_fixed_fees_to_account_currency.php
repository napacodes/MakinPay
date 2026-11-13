<?php

use App\Support\DecimalMoney;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restate every configured fixed fee into the currency it is charged in.
 *
 * A fixed fee component used to be written in the platform's base currency and
 * multiplied by the conversion rate on the way out, even though the admin form
 * has always labelled the input with the method's own currency ("Fixed 0.30
 * EUR"). An operator who typed 2 into a EUR method's fixed field got 2 USD
 * charged, not EUR 2.
 *
 * The code now reads these columns as being denominated in the account
 * currency, which is how processors publish fee schedules — Stripe lists
 * EUR 0.25 for EEA and USD 0.30 for US as separate figures rather than
 * converting one into the other, because a converted fixed fee would drift
 * with the exchange rate every day.
 *
 * Changing that reading without touching the data would silently raise or
 * lower what every existing customer is charged. So each stored value is
 * multiplied by its rate here: the number changes, the money charged does not.
 *
 *     EUR sender fee_fixed 2.00 @ rate 0.85  ->  1.70
 *     charged before: 2.00 base  ->  1.70 EUR
 *     charged after:  1.70 EUR   ->  1.70 EUR
 *
 * Rows at rate 1 are arithmetically untouched, which is every deposit and
 * withdraw method on a single-currency install.
 */
return new class extends Migration
{
    /**
     * Columns denominated in the account currency, per table, along with how
     * to reach that table's rate.
     *
     * @var array<string, array{rate: string, columns: list<string>}>
     */
    private const TABLES = [
        'deposit_methods' => [
            'rate'    => 'conversion_rate',
            'columns' => [
                'charge_fixed', 'charge_min', 'charge_max',
                'user_charge_fixed', 'user_charge_min', 'user_charge_max',
                'merchant_charge_fixed', 'merchant_charge_min', 'merchant_charge_max',
                'agent_charge_fixed', 'agent_charge_min', 'agent_charge_max',
            ],
        ],
        'withdraw_methods' => [
            'rate'    => 'conversion_rate',
            'columns' => [
                'charge_fixed', 'charge_min', 'charge_max',
                'user_charge_fixed', 'user_charge_min', 'user_charge_max',
                'merchant_charge_fixed', 'merchant_charge_min', 'merchant_charge_max',
                'agent_charge_fixed', 'agent_charge_min', 'agent_charge_max',
            ],
        ],
    ];

    public function up(): void
    {
        $this->restate(fn (string $value, string $rate): string => DecimalMoney::multiply($value, $rate));
    }

    public function down(): void
    {
        $this->restate(fn (string $value, string $rate): string => bcdiv($value, $rate, DecimalMoney::CALCULATION_SCALE));
    }

    private function restate(callable $convert): void
    {
        foreach (self::TABLES as $table => $spec) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = array_values(array_filter(
                $spec['columns'],
                fn (string $column): bool => Schema::hasColumn($table, $column),
            ));

            if ($columns === [] || ! Schema::hasColumn($table, $spec['rate'])) {
                continue;
            }

            $this->restateRows($table, $spec['rate'], $columns, $convert);
        }

        $this->restateCurrencyRoles($convert);
    }

    /**
     * Currency roles take their rate from the currency they belong to rather
     * than from a column on their own row.
     */
    private function restateCurrencyRoles(callable $convert): void
    {
        if (! Schema::hasTable('currency_roles') || ! Schema::hasTable('currencies')) {
            return;
        }

        $columns = array_values(array_filter(
            ['fee_fixed', 'fee_min', 'fee_max'],
            fn (string $column): bool => Schema::hasColumn('currency_roles', $column),
        ));

        if ($columns === []) {
            return;
        }

        $rates = DB::table('currencies')->pluck('exchange_rate', 'id');

        DB::table('currency_roles')->orderBy('id')->chunkById(200, function ($rows) use ($columns, $rates, $convert): void {
            foreach ($rows as $row) {
                $rate = (string) ($rates[$row->currency_id] ?? 1);

                $this->applyRow('currency_roles', $row, $columns, $rate, $convert);
            }
        });
    }

    /** @param list<string> $columns */
    private function restateRows(string $table, string $rateColumn, array $columns, callable $convert): void
    {
        DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($table, $rateColumn, $columns, $convert): void {
            foreach ($rows as $row) {
                $this->applyRow($table, $row, $columns, (string) ($row->{$rateColumn} ?? 1), $convert);
            }
        });
    }

    /**
     * A rate of zero or one leaves the row alone: one is a no-op, and zero is
     * a broken configuration that must not be turned into a division by zero
     * or a wiped fee.
     *
     * @param list<string> $columns
     */
    private function applyRow(string $table, object $row, array $columns, string $rate, callable $convert): void
    {
        if (! is_numeric($rate) || bccomp($rate, '0', 18) <= 0 || bccomp($rate, '1', 18) === 0) {
            return;
        }

        $updates = [];

        foreach ($columns as $column) {
            $value = $row->{$column} ?? null;

            // Null means "no cap", which stays null. Zero converts to zero, so
            // there is nothing to write.
            if ($value === null || ! is_numeric($value) || bccomp((string) $value, '0', 8) === 0) {
                continue;
            }

            $updates[$column] = $convert((string) $value, $rate);
        }

        if ($updates !== []) {
            DB::table($table)->where('id', $row->id)->update($updates);
        }
    }
};
