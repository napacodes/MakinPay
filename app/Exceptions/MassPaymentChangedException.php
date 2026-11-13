<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\Api\ApiErrorCode;
use App\Models\MassPaymentBatch;

/**
 * The batch the customer is confirming is no longer the batch they reviewed.
 *
 * Thrown when the re-validated rows priced or resolved differently from the
 * stored preview (the draft has then been refreshed under its lock), or when
 * the fingerprint or expected total the client sent does not match the stored
 * preview. Nothing was debited. `$batch` is the batch as it now stands, so a
 * caller can show the refreshed preview straight away.
 */
class MassPaymentChangedException extends NotifyErrorException
{
    public function __construct(public readonly MassPaymentBatch $batch, ?string $message = null)
    {
        parent::__construct($message ?? __('The batch changed since you reviewed it. Review it again.'), 409);
    }

    /** Its own 409, never mistaken for an idempotency conflict. */
    protected function apiErrorCode(int $status): ApiErrorCode
    {
        return ApiErrorCode::MassPaymentChanged;
    }
}
