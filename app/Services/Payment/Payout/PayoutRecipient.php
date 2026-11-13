<?php

declare(strict_types=1);

namespace App\Services\Payment\Payout;

/**
 * Who a mass payout line pays, as normalised field values. Built from the
 * decrypted row details and never persisted or logged in this form.
 */
final readonly class PayoutRecipient
{
    /**
     * @param array<string, mixed> $details normalised withdraw-method field values keyed by field name
     */
    public function __construct(
        public ?string $country,
        public string $accountName,
        public array $details,
    ) {}

    /**
     * The first non-blank scalar value among the given field aliases,
     * trimmed, or null when none of them holds one.
     *
     * @param array<int, string> $keys
     */
    public function detail(array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->details[$key] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }
}
