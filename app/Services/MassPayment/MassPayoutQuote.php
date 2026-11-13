<?php

declare(strict_types=1);

namespace App\Services\MassPayment;

use App\Enums\MassPayment\MassPaymentIssue;

/**
 * One mass payout line priced: either its frozen figures and no issue, or an
 * issue and no figures, so a refused line can never be stored with numbers
 * that look payable.
 *
 * Every figure is a decimal string. `entered_amount`, `net_amount`,
 * `payable_amount` and `wallet_fee` are in the method (= wallet) currency at
 * its own precision; `base_amount` and `base_fee` are in the site currency at
 * the calculation scale; `exchange_rate` is the method rate they were derived
 * with.
 */
readonly class MassPayoutQuote
{
    /**
     * @param array{entered_amount: string, base_amount: string, base_fee: string, net_amount: string, payable_amount: string, wallet_fee: string, 'exchange_rate': string}|null $figures
     */
    public function __construct(
        public ?array $figures,
        public ?MassPaymentIssue $issue,
    ) {}

    /**
     * @param array{entered_amount: string, base_amount: string, base_fee: string, net_amount: string, payable_amount: string, wallet_fee: string, 'exchange_rate': string} $figures
     */
    public static function priced(array $figures): self
    {
        return new self($figures, null);
    }

    public static function refused(MassPaymentIssue $issue): self
    {
        return new self(null, $issue);
    }

    public function isPayable(): bool
    {
        return $this->issue === null && $this->figures !== null;
    }
}
