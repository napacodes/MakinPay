<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\MassPayment;

use App\Http\Requests\MassPayment\ConfirmMassPaymentRequest as WebConfirmMassPaymentRequest;
use App\Services\MassPayment\MassPaymentService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The app confirms the draft it showed the customer.
 *
 * The web form's rules and messages exactly: the preview's `fingerprint` and
 * `expected_total`, the two opt-ins, and the wallet PIN from a customer who
 * has set one. Only two things differ. The confirmation is recorded as
 * coming from the API, and a refusal is the VALIDATION_FAILED envelope
 * rather than the web form's redirect with the PIN stripped from old input
 * (there is no session here to flash it into).
 *
 * The Idempotency-Key header is the route middleware's business, not a
 * field; confirming twice is safe without it anyway, because the service
 * answers a batch that is already past Draft as a replay.
 */
class ConfirmMassPaymentRequest extends WebConfirmMassPaymentRequest
{
    protected function channel(): string
    {
        return MassPaymentService::CHANNEL_API;
    }

    /**
     * @throws ValidationException
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException($validator);
    }
}
