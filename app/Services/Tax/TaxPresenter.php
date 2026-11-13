<?php

declare(strict_types=1);

namespace App\Services\Tax;

use App\Models\Transaction;
use App\Support\ApiMoney;

/**
 * The one shape a tax takes wherever it is shown outside the engine: the API
 * previews and resources, and the receipts read the same keys, so a tax line
 * reads the same on every surface.
 *
 *   amount  the total, an ApiMoney string at the currency's precision
 *   lines   one {name, amount} per rule, in display order
 *
 * A quote or a transaction with no tax answers amount "0" at the currency's
 * places and no lines.
 */
final class TaxPresenter
{
    /**
     * @return array{amount: string, lines: list<array{name: string, amount: string}>}
     */
    public static function api(TaxQuote $quote): array
    {
        return [
            'amount' => ApiMoney::amount($quote->total, $quote->currency),
            'lines'  => array_map(static fn (TaxLine $line): array => [
                'name'   => $line->name,
                'amount' => ApiMoney::amount($line->amount, $quote->currency),
            ], $quote->lines),
        ];
    }

    /**
     * The tax a transaction row stored, in its payable currency.
     *
     * @return array{amount: string, lines: list<array{name: string, amount: string}>}
     */
    public static function transaction(Transaction $transaction): array
    {
        return self::stored(
            $transaction->wallet_tax === null ? null : (string) $transaction->wallet_tax,
            $transaction->tax_breakdown,
            (string) ($transaction->payable_currency ?: $transaction->currency),
        );
    }

    /**
     * A tax stored as a total and a breakdown - on a transaction, a
     * remittance quote or transfer - in the currency it was charged in.
     *
     * @return array{amount: string, lines: list<array{name: string, amount: string}>}
     */
    public static function stored(?string $total, mixed $breakdown, string $currency): array
    {
        $breakdown = is_array($breakdown) ? $breakdown : [];

        return [
            'amount' => ApiMoney::amount($total ?? '0', $currency),
            'lines'  => array_values(array_map(static fn (array $line): array => [
                'name'   => (string) ($line['name'] ?? ''),
                'amount' => ApiMoney::amount((string) ($line['amount'] ?? '0'), $currency),
            ], array_filter($breakdown, 'is_array'))),
        ];
    }
}
