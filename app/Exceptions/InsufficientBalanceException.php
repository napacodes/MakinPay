<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\Api\ApiErrorCode;
use Throwable;

/**
 * The wallet holds less than the operation needs.
 *
 * A NotifyErrorException, so a browser gets the usual flash and redirect and
 * nothing that already catches NotifyErrorException changes behaviour. It is
 * its own type so an API surface can answer with
 * ApiErrorCode::InsufficientBalance instead of a generic refusal.
 *
 * It is thrown only before anything is written: the guarded debit
 * (WalletService::tryDebit) refused, so the balance and the ledger are
 * exactly as they were.
 */
class InsufficientBalanceException extends NotifyErrorException
{
    public function __construct(?string $message = null, int $code = 422, ?Throwable $previous = null)
    {
        parent::__construct($message ?? __('Insufficient wallet balance.'), $code, $previous);
    }

    /** A short balance is INSUFFICIENT_BALANCE, not a bad field. */
    protected function apiErrorCode(int $status): ApiErrorCode
    {
        return ApiErrorCode::InsufficientBalance;
    }
}
