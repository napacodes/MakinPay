<?php

declare(strict_types=1);

namespace App\Services\Tax;

use App\Support\DecimalMoney;

/**
 * The tax one transaction carries: a line per rule that applied, their total
 * in the currency the customer pays in, and the same total in the site
 * currency for reporting. Every figure is an exact decimal string.
 *
 * A quote with no lines is the "no tax" quote: its totals are zero, and a
 * flow that adds {@see self::$total} to what it charges charges exactly what
 * it did before GF6.
 */
final readonly class TaxQuote
{
    /**
     * @param list<TaxLine> $lines
     */
    public function __construct(
        public string $currency,
        public int $decimals,
        public array $lines,
        public string $total,
        public string $baseTotal,
    ) {}

    public static function none(string $currency, int $decimals): self
    {
        return new self($currency, $decimals, [], DecimalMoney::normalize('0', $decimals), DecimalMoney::normalize('0', DecimalMoney::CALCULATION_SCALE));
    }

    public function isEmpty(): bool
    {
        return $this->lines === [] || DecimalMoney::compare($this->total, '0') === 0;
    }

    /**
     * The lines as stored and shown, or null when there is no tax, so a
     * transaction with no tax keeps a null breakdown.
     *
     * @return list<array{rule_id: int, name: string, percent: string, fixed: string, base: string, amount: string}>|null
     */
    public function breakdown(): ?array
    {
        return $this->isEmpty() ? null : array_map(static fn (TaxLine $line): array => $line->toArray(), $this->lines);
    }

    /**
     * The columns a transaction row stores this tax in, null when there is
     * none: `wallet_tax` inside the payable, `base_tax` for reporting.
     *
     * @return array{wallet_tax: string|null, base_tax: string|null, tax_breakdown: list<array<string, mixed>>|null}
     */
    public function columns(): array
    {
        return $this->isEmpty()
            ? ['wallet_tax' => null, 'base_tax' => null, 'tax_breakdown' => null]
            : ['wallet_tax' => $this->total, 'base_tax' => $this->baseTotal, 'tax_breakdown' => $this->breakdown()];
    }
}
