<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\Api\ApiErrorCode;
use Throwable;

/**
 * The customer has a wallet PIN and did not send it.
 *
 * A NotifyErrorException, so the web keeps its flash and redirect and every
 * existing catch still holds; its own type so the API answers
 * WALLET_PIN_REQUIRED - the app can then ask for the PIN - instead of a
 * generic refusal it would have to read the sentence of.
 */
class WalletPinRequiredException extends NotifyErrorException
{
    public function __construct(?string $message = null, int $code = 422, ?Throwable $previous = null)
    {
        parent::__construct($message ?? __('Your wallet PIN is required for this action.'), $code, $previous);
    }

    protected function apiErrorCode(int $status): ApiErrorCode
    {
        return ApiErrorCode::WalletPinRequired;
    }
}
