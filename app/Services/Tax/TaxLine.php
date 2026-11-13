<?php

declare(strict_types=1);

namespace App\Services\Tax;

/**
 * One rule's share of a transaction's tax, in the currency the customer pays
 * in, as an exact decimal string at that currency's precision.
 */
final readonly class TaxLine
{
    public function __construct(
        public int $ruleId,
        public string $name,
        public string $percent,
        public string $fixed,
        public string $base,
        public string $amount,
    ) {}

    /**
     * The line as it is stored in `transactions.tax_breakdown` and shown in
     * previews, receipts and the API.
     *
     * @return array{rule_id: int, name: string, percent: string, fixed: string, base: string, amount: string}
     */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'name'    => $this->name,
            'percent' => $this->percent,
            'fixed'   => $this->fixed,
            'base'    => $this->base,
            'amount'  => $this->amount,
        ];
    }
}
