<?php

declare(strict_types=1);

namespace App\Services\BillPayment;

/**
 * One "who owns this customer number?" question for a provider.
 *
 * `billerExternalCode` and `itemCode` are the provider's own codes, as stored
 * on the biller and item. The customer number is personal data: it is never
 * logged and never used as a cache key in the clear.
 */
readonly class BillCustomerQuery
{
    public function __construct(
        public string $billerExternalCode,
        public ?string $itemCode,
        public string $country,
        public string $customerNumber,
    ) {}
}
