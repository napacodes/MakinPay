<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Enums\AmountFlow;
use App\Enums\TrxStatus;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Support\ApiMoney;
use App\Support\DecimalMoney;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Monthly statements for one wallet, and the same rows for any filtered
 * list of transactions (the Activity export).
 *
 * A month's closing balance is walked back from the balance held now,
 * the way BalanceHistoryService walks days: nothing stores a historical
 * balance, and a figure derived from the ledger is the one that matches it.
 */
class StatementService
{
    private const MAX_MONTHS = 24;

    /**
     * The months that have movement, newest first, each with its count and
     * closing balance in the wallet's own currency.
     *
     * @return list<array{period: string, label: string, transactions: int, closing: string}>
     */
    public function months(Wallet $wallet): array
    {
        $code = strtoupper((string) $wallet->currency?->code);
        $rows = $wallet->transactions()
            ->where('status', TrxStatus::COMPLETED)
            ->withoutSandbox()
            ->get(['created_at', 'amount_flow', 'currency', 'net_amount', 'payable_amount', 'payable_currency']);

        if ($rows->isEmpty()) {
            return [];
        }

        $byMonth = $rows->groupBy(fn (Transaction $row): string => $row->created_at->format('Y-m'));
        $months  = [];
        $closing = DecimalMoney::normalize((string) $wallet->balance);
        $cursor  = CarbonImmutable::now()->startOfMonth();
        $oldest  = CarbonImmutable::parse((string) $rows->min('created_at'))->startOfMonth();
        $limit   = 0;

        while ($cursor->greaterThanOrEqualTo($oldest) && $limit++ < self::MAX_MONTHS) {
            $period = $cursor->format('Y-m');
            $rowsIn = $byMonth->get($period, collect());

            if ($rowsIn->isNotEmpty()) {
                $months[] = [
                    'period'       => $period,
                    'label'        => $cursor->format('F Y'),
                    'transactions' => $rowsIn->count(),
                    'closing'      => ApiMoney::amount($closing, $code),
                ];
            }

            $closing = DecimalMoney::subtract($closing, $this->movement($rowsIn, $code), DecimalMoney::CALCULATION_SCALE);
            $cursor  = $cursor->subMonth();
        }

        return $months;
    }

    /**
     * The rows of one month, oldest first. Sandbox payments are left out:
     * a statement is the wallet's record against its closing balance, and a
     * test payment never moved it. The Activity list and its export keep them.
     *
     * @return Collection<int, Transaction>
     */
    public function rows(Wallet $wallet, string $period): Collection
    {
        $start = CarbonImmutable::createFromFormat('Y-m', $period)->startOfMonth();

        return $wallet->transactions()->withoutSandbox()->whereBetween('created_at', [$start, $start->endOfMonth()])->orderBy('created_at')->get();
    }

    public static function isPeriod(string $period): bool
    {
        return preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $period) === 1;
    }

    /**
     * @param iterable<Transaction> $rows
     */
    public function csv(iterable $rows): string
    {
        $lines = ['date,reference,type,status,description,amount,fee,currency,wallet_amount,wallet_currency'];

        foreach ($rows as $row) {
            $cells = [
                $row->created_at?->toIso8601String(),
                $row->trx_id,
                $row->trx_type?->value,
                $row->status?->value,
                $row->description,
                ($row->amount_flow === AmountFlow::MINUS ? '-' : '').ApiMoney::amount($row->amount, $row->currency),
                ApiMoney::amount($row->fee, $row->currency),
                $row->currency,
                $row->payable_amount === null ? '' : ApiMoney::amount($row->payable_amount, $row->payable_currency),
                $row->payable_currency,
            ];
            $lines[] = implode(',', array_map(fn ($cell): string => '"'.str_replace('"', '""', (string) $cell).'"', $cells));
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param iterable<Transaction> $rows
     * @param array<string, mixed>  $heading title, name, wallet, currency, closing
     */
    public function pdf(iterable $rows, array $heading): string
    {
        return Pdf::loadView('api.statement', ['rows' => $rows, 'heading' => $heading])->setPaper('a4')->output();
    }

    /**
     * Signed wallet movement over completed rows - the reading
     * BalanceHistoryService makes of a row.
     *
     * @param Collection<int, Transaction> $rows
     */
    private function movement(Collection $rows, string $code): string
    {
        $sum = '0';

        foreach ($rows as $row) {
            $payableCode = strtoupper(trim((string) $row->getRawOriginal('payable_currency')));
            $payable     = $row->getRawOriginal('payable_amount');
            $inWallet    = strtoupper(trim((string) $row->getRawOriginal('currency'))) === $code;
            $moved       = $payable !== null && $payableCode                           === $code ? (string) $payable : ($inWallet ? (string) ($row->getRawOriginal('net_amount') ?? 0) : null);
            $flow        = $row->amount_flow;

            if ($moved === null || ! $flow instanceof AmountFlow || $flow === AmountFlow::DEFAULT) {
                continue;
            }

            $signed = $flow === AmountFlow::MINUS ? '-'.DecimalMoney::normalize($moved) : DecimalMoney::normalize($moved);
            $sum    = DecimalMoney::add($sum, $signed, DecimalMoney::CALCULATION_SCALE);
        }

        return $sum;
    }
}
