<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Outcome of a base-currency rebase.
 *
 * @phpstan-type RebasedRate array{code: string, old_rate: string, new_rate: string}
 */
readonly class RebaseReport
{
    /**
     * @param list<array{code: string, old_rate: string, new_rate: string}> $rates
     */
    public function __construct(
        public string $batchUuid,
        public string $oldBaseCode,
        public string $newBaseCode,
        public string $divisor,
        public array $rates,
    ) {}

    public function count(): int
    {
        return count($this->rates);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'batch_uuid'    => $this->batchUuid,
            'old_base_code' => $this->oldBaseCode,
            'new_base_code' => $this->newBaseCode,
            'divisor'       => $this->divisor,
            'rates'         => $this->rates,
        ];
    }
}
