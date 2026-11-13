<?php

declare(strict_types=1);

namespace App\Services\BillPayment;

use App\Enums\BillPayment\BillCustomerLookupStatus;

/**
 * A provider's answer to a {@see BillCustomerQuery}.
 *
 *   status        Verified, NotFound or Unavailable
 *   customerName  the account holder's name, when the provider gave one
 *   message       a plain-words explanation, when there is one to show
 *
 * The name is personal data: it may be shown to the customer and stored on
 * the payment, but it is never logged.
 */
readonly class BillCustomerLookup
{
    public function __construct(
        public BillCustomerLookupStatus $status,
        public ?string $customerName = null,
        public ?string $message = null,
    ) {}

    public static function verified(?string $customerName = null): self
    {
        $customerName = $customerName === null ? null : trim($customerName);

        return new self(BillCustomerLookupStatus::Verified, $customerName === '' ? null : $customerName);
    }

    public static function notFound(?string $message = null): self
    {
        return new self(BillCustomerLookupStatus::NotFound, null, $message);
    }

    public static function unavailable(?string $message = null): self
    {
        return new self(BillCustomerLookupStatus::Unavailable, null, $message);
    }
}
