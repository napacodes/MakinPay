<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\Api\ApiErrorCode;

/**
 * The withdraw method a mass payout names cannot be used from the wallet:
 * not opted in, switched off, in another currency, its driver not ready, or
 * no such method at all. Every one of those reads the same, so an id never
 * reveals whether a method exists.
 *
 * A NotifyErrorException, so the web gets its flash and redirect; the app
 * gets MASS_PAYOUT_METHOD_UNAVAILABLE to branch on, the same at draft and at
 * confirm. Nothing is written and nothing is debited when it is thrown.
 */
class MassPayoutMethodUnavailableException extends NotifyErrorException
{
    public function __construct(string $message)
    {
        parent::__construct($message, ApiErrorCode::MassPayoutMethodUnavailable->status());
    }

    protected function apiErrorCode(int $status): ApiErrorCode
    {
        return ApiErrorCode::MassPayoutMethodUnavailable;
    }
}
