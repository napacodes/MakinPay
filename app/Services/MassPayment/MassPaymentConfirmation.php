<?php

declare(strict_types=1);

namespace App\Services\MassPayment;

/**
 * What the customer sent to confirm a draft: the wallet PIN, the preview they
 * reviewed (its fingerprint and the total to reserve), and their two
 * explicit choices. Skipping invalid rows and acknowledging duplicate
 * warnings are opt-ins; both default to no.
 */
readonly class MassPaymentConfirmation
{
    public function __construct(
        public ?string $pin,
        public string $fingerprint,
        public string $expectedTotal,
        public bool $skipInvalid = false,
        public bool $acknowledgeWarnings = false,
        public string $channel = MassPaymentService::CHANNEL_WEB,
    ) {}
}
