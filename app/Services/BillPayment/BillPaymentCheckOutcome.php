<?php

declare(strict_types=1);

namespace App\Services\BillPayment;

use App\Enums\BillPayment\BillPaymentOutcome;

/**
 * How one status check of a payment with its provider ended.
 *
 *   checked  whether the provider was asked at all (false: another check was
 *            running, the payment is not the provider's, its driver cannot
 *            be asked, or there is nothing left to ask about)
 *   outcome  the provider's answer as a BillPaymentOutcome value, when asked
 *   message  what happened, in plain words for an admin
 *   action   what applying the answer did, when it was applied
 */
readonly class BillPaymentCheckOutcome
{
    public function __construct(
        public bool $checked,
        public ?string $outcome,
        public string $message,
        public ?BillPaymentActionResult $action = null,
    ) {}

    public static function skipped(string $message): self
    {
        return new self(false, null, $message);
    }

    public static function asked(BillPaymentOutcome $outcome, string $message, ?BillPaymentActionResult $action = null): self
    {
        return new self(true, $outcome->value, $message, $action);
    }
}
