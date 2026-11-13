<?php

declare(strict_types=1);

namespace App\Data;

/**
 * The components of a computed fee, kept separate so a UI can show
 * "0.0005 BTC + 0.5% = 0.00135 BTC" without recomputing anything.
 *
 * Every value is a decimal string at the working scale — never a float.
 */
readonly class FeeBreakdown
{
    public function __construct(
        public string $fixed,
        public string $percent,
        public string $percentAmount,
        public string $total,
        public string $net,
        public bool $clamped,
    ) {}

    public function toFloat(): float
    {
        return (float) $this->total;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fixed'          => $this->fixed,
            'percent'        => $this->percent,
            'percent_amount' => $this->percentAmount,
            'total'          => $this->total,
            'net'            => $this->net,
            'clamped'        => $this->clamped,
        ];
    }
}
