<?php

use App\Support\DecimalMoney;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repair the denomination label on historical deposit and withdrawal rows.
 *
 * `PaymentService` has always written `amount` and `fee` in the base currency —
 * `net_amount` / `payable_amount` are the wallet-currency side — but it stamped
 * the wallet's code into `currency`, the column that is supposed to name the
 * unit `amount` is in. Every reader that trusted the pair therefore converted a
 * figure that was already in base: the admin dashboard divided a $1,000 BTC
 * deposit by 0.000016 and reported $62,500,000.
 *
 * The writer is fixed. This restates the rows it already wrote.
 *
 * Nothing is invented. `amount` and `fee` are copied into the snapshot they
 * always belonged in, `net_amount` supplies the wallet side, and the rate is
 * recovered exactly rather than guessed: the writer computed
 * `net_amount = amount x conversion_rate`, so the rate it used is
 * `net_amount / amount`. Rows that cannot support that division keep a NULL
 * rate for `money:audit-precision` to report.
 *
 * A recovered rate is not the same fact as one the writer captured, so
 * `rate_source` records which it is — the same distinction `currencies` already
 * draws between a live, manual and rebased rate.
 *
 * `PaymentService` is the only writer of DEPOSIT and WITHDRAW transactions, so
 * the type filter selects exactly the affected rows and nothing else. Flows
 * that genuinely work in the wallet's own currency — agent, P2P, marketplace —
 * are correctly labelled and are not touched.
 */
return new class extends Migration
{
    private const TYPES = ['deposit', 'withdraw'];

    private const RESTATED = 'restated';

    public function up(): void
    {
        if (! $this->ledgerCarriesSnapshot()) {
            return;
        }

        $this->addRateSourceColumn();

        $baseCode = $this->baseCurrencyCode();

        if ($baseCode === '') {
            return;
        }

        $this->restateSnapshots($baseCode);

        // Correct the legacy label last, so the snapshot pass above can still
        // tell the affected rows apart while it runs.
        DB::table('transactions')
            ->whereIn('trx_type', self::TYPES)
            ->whereNotNull('currency')
            ->where('currency', '!=', $baseCode)
            ->update(['currency' => $baseCode]);
    }

    /**
     * The wallet-currency label is recoverable from `payable_currency`, which
     * the same writer set to the method's currency — and a method may only
     * serve a wallet of its own currency. Rows this migration derived are
     * emptied again, so re-running up() rebuilds them identically.
     */
    public function down(): void
    {
        if (! $this->ledgerCarriesSnapshot()) {
            return;
        }

        DB::table('transactions')
            ->whereIn('trx_type', self::TYPES)
            ->whereNotNull('payable_currency')
            ->update(['currency' => DB::raw('payable_currency')]);

        if (Schema::hasColumn('transactions', 'rate_source')) {
            DB::table('transactions')
                ->where('rate_source', self::RESTATED)
                ->update([
                    'exchange_rate' => null,
                    'base_amount'   => null,
                    'base_fee'      => null,
                    'wallet_amount' => null,
                    'wallet_fee'    => null,
                ]);

            Schema::table('transactions', function (Blueprint $table): void {
                $table->dropColumn('rate_source');
            });
        }
    }

    private function addRateSourceColumn(): void
    {
        if (Schema::hasColumn('transactions', 'rate_source')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('rate_source', 20)->nullable()->after('exchange_rate')
                ->comment('How exchange_rate was established; NULL when the writer captured it.');
        });
    }

    private function restateSnapshots(string $baseCode): void
    {
        DB::table('transactions')
            ->whereIn('trx_type', self::TYPES)
            ->whereNull('base_amount')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($baseCode): void {
                foreach ($rows as $row) {
                    DB::table('transactions')
                        ->where('id', $row->id)
                        ->update($this->snapshotFor($row, $baseCode));
                }
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotFor(object $row, string $baseCode): array
    {
        $scale     = DecimalMoney::CALCULATION_SCALE;
        $amount    = DecimalMoney::normalize((string) ($row->amount ?? 0));
        $fee       = DecimalMoney::normalize((string) ($row->fee ?? 0));
        $netAmount = DecimalMoney::normalize((string) ($row->net_amount ?? 0));
        $payable   = DecimalMoney::normalize((string) ($row->payable_amount ?? 0));

        $snapshot = [
            'base_amount'   => $amount,
            'base_fee'      => $fee,
            'base_currency' => $baseCode,
            'wallet_amount' => $netAmount,
            // The wallet-side fee is what the customer paid on top of the net
            // figure. A payable total below the net amount is corrupt rather
            // than a negative fee, so it records zero.
            'wallet_fee' => bccomp($payable, $netAmount, $scale) > 0
                ? bcsub($payable, $netAmount, $scale)
                : '0',
        ];

        $recoverable = bccomp($amount, '0', $scale) > 0 && bccomp($netAmount, '0', $scale) > 0;

        if (! $recoverable) {
            return $snapshot;
        }

        return $snapshot + [
            'exchange_rate' => bcdiv($netAmount, $amount, DecimalMoney::RATE_SCALE),
            'rate_source'   => self::RESTATED,
        ];
    }

    private function ledgerCarriesSnapshot(): bool
    {
        return Schema::hasTable('transactions') && Schema::hasColumn('transactions', 'base_amount');
    }

    private function baseCurrencyCode(): string
    {
        if (! Schema::hasTable('currencies')) {
            return '';
        }

        return strtoupper((string) (DB::table('currencies')->where('default', true)->value('code') ?? ''));
    }
};
