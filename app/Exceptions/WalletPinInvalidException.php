<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\Api\ApiErrorCode;
use Throwable;

/**
 * The wallet PIN sent was wrong - and counted against the lockout.
 *
 * Its own type so the API answers WALLET_PIN_INVALID whatever sentence the
 * caller chose for it (WalletPinGuard lets a caller rename the refusal), and
 * the app shakes the PIN dots rather than parsing the message.
 */
class WalletPinInvalidException extends NotifyErrorException
{
    public function __construct(?string $message = null, int $code = 422, ?Throwable $previous = null)
    {
        parent::__construct($message ?? __('The wallet PIN is incorrect.'), $code, $previous);
    }

    protected function apiErrorCode(int $status): ApiErrorCode
    {
        return ApiErrorCode::WalletPinInvalid;
    }
}
