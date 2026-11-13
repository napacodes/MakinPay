<?php

declare(strict_types=1);

namespace App\Services\BillPayment;

use App\Services\Tax\TaxQuote;

/**
 * What a payment will cost, before any money moves.
 *
 * Every amount is an exact decimal string, never a float, rounded only at the
 * currency's own precision (`decimals`). `billAmount` and `billCurrency` are
 * what the biller receives; `amount`, `fee` and `total` are what the wallet
 * pays, in `currency`. In GF2 the two currencies are always equal.
 *
 * `sufficientBalance` is advisory: only the locked debit decides.
 */
readonly class BillPaymentQuote
{
    public function __construct(
        public string $billAmount,
        public string $billCurrency,
        public string $amount,
        public string $fee,
        public string $total,
        public string $currency,
        public int $decimals,
        public string $feeFixed,
        public string $feePercent,
        public string $feeDescription,
        public string $balance,
        public string $balanceAfter,
        public bool $sufficientBalance,
        public ?TaxQuote $tax = null,
    ) {}

    /**
     * GF6: the tax, empty when none applies. It is inside `total` and apart
     * from `fee`.
     */
    public function taxQuote(): TaxQuote
    {
        return $this->tax ?? TaxQuote::none($this->currency, $this->decimals);
    }
}
