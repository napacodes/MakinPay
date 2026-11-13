<?php

declare(strict_types=1);

namespace App\Services\BillPayment;

use App\Models\BillPayment;
use Carbon\CarbonImmutable;

/**
 * Everything a fulfiller needs to pay one bill, and nothing it does not.
 *
 * Built from the payment's own snapshots, so a biller edited after the money
 * was reserved never changes what is paid. `amount` and `currency` are what
 * the biller receives (`bill_amount`, `bill_currency`), as an exact decimal
 * string at the column's scale. The token, the wallet and the customer's
 * identity stay out.
 *
 * `providerTransactionId` is the provider's own id once a reply bound one to
 * the payment, so a status check can look the payment up by it;
 * `submittedAt` is when the payment was handed to the driver. Both are null
 * before dispatch.
 */
readonly class BillPaymentOrder
{
    /**
     * @param array<string, mixed> $metadata the payment's metadata, which is sanitised and never holds a secret
     */
    public function __construct(
        public int $paymentId,
        public string $uuid,
        public string $reference,
        public string $providerRequestId,
        public int $billerId,
        public ?string $billerExternalCode,
        public ?string $itemCode,
        public string $country,
        public string $categoryCode,
        public string $customerNumber,
        public string $amount,
        public string $currency,
        public array $metadata = [],
        public ?string $providerTransactionId = null,
        public ?CarbonImmutable $submittedAt = null,
    ) {}

    public static function from(BillPayment $payment): self
    {
        return new self(
            paymentId: (int) $payment->getKey(),
            uuid: (string) $payment->uuid,
            reference: (string) $payment->reference,
            providerRequestId: (string) $payment->provider_request_id,
            billerId: (int) $payment->biller_id,
            billerExternalCode: $payment->biller?->external_code,
            itemCode: $payment->item_code,
            country: (string) $payment->country,
            categoryCode: (string) $payment->category_code,
            customerNumber: (string) $payment->customer_number,
            amount: (string) $payment->bill_amount,
            currency: (string) $payment->bill_currency,
            metadata: is_array($payment->metadata) ? $payment->metadata : [],
            providerTransactionId: $payment->provider_transaction_id === null ? null : (string) $payment->provider_transaction_id,
            submittedAt: $payment->submitted_at?->toImmutable(),
        );
    }
}
