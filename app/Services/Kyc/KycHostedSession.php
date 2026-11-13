<?php

declare(strict_types=1);

namespace App\Services\Kyc;

use Illuminate\Support\Carbon;

/**
 * A live provider-hosted verification journey: where to send the applicant and
 * the reference their eventual webhook will carry.
 *
 * Returned by drivers that can host the capture flow themselves, so the
 * applicant photographs their documents inside the provider's page rather than
 * uploading them to us first.
 */
final class KycHostedSession
{
    /**
     * @param array<string, mixed> $payload Raw provider response, kept for audit.
     */
    public function __construct(
        public string $url,
        public string $reference,
        public ?Carbon $expiresAt = null,
        public array $payload = [],
    ) {}
}
