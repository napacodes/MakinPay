<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Transaction;

/**
 * What a provider callback is allowed to rely on before it settles anything.
 *
 * A callback names one of our transactions and one of the provider's payments.
 * The gateway binding in GatewayCallbackContext already stops a callback at one
 * gateway's endpoint from settling another gateway's row. Two questions are
 * left, and both are answered here: does the named row exist, and did the
 * provider really take the amount that row is owed? The second is asked of the
 * provider's own answer — a status lookup, a signed body — and never of the
 * callback fields, which anyone on the internet can post.
 *
 * Amounts are compared as decimals against `payable_amount`, which is the
 * figure every driver handed to the provider at deposit time, so the two sides
 * are the same number in the same currency.
 */
final class CallbackSettlement
{
    /**
     * The transaction a callback claims to be about, or null when no such row
     * exists. Status is not checked here: TransactionService refuses to settle
     * anything that is not pending and logs why, and a callback for an already
     * completed row is a retry, not an attack.
     */
    public static function find(?string $trxId): ?Transaction
    {
        $trxId = trim((string) $trxId);

        if ($trxId === '') {
            return null;
        }

        return Transaction::query()->where('trx_id', $trxId)->first();
    }

    /**
     * Exactly what the customer owes, in the currency the provider was asked
     * for when one is given.
     */
    public static function amountMatches(Transaction $transaction, mixed $amount, mixed $currency = null): bool
    {
        return self::compare($transaction, $amount, $currency) === 0;
    }

    /**
     * At least what the customer owes. For on-chain deposits, where the payer
     * chooses the amount and paying more is their business, not a mismatch.
     */
    public static function amountCovers(Transaction $transaction, mixed $amount, mixed $currency = null): bool
    {
        $comparison = self::compare($transaction, $amount, $currency);

        return $comparison !== null && $comparison >= 0;
    }

    /**
     * Null means "cannot be compared" — a missing or non-numeric amount, or a
     * currency that is not the one owed — and never settles.
     */
    private static function compare(Transaction $transaction, mixed $amount, mixed $currency): ?int
    {
        if (! is_numeric($amount)) {
            return null;
        }

        if ($currency !== null && trim((string) $currency) !== '' && ! self::currencyMatches($transaction, (string) $currency)) {
            return null;
        }

        return DecimalMoney::compare((string) $amount, (string) $transaction->payable_amount);
    }

    private static function currencyMatches(Transaction $transaction, string $currency): bool
    {
        $expected = (string) ($transaction->payable_currency ?: $transaction->currency);

        return strcasecmp(trim($currency), trim($expected)) === 0;
    }
}
