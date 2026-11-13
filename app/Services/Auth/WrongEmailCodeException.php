<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\NotifyErrorException;

/**
 * A wrong code, carrying how many tries are left so the app can say so.
 */
class WrongEmailCodeException extends NotifyErrorException
{
    public function __construct(string $message, public readonly int $attemptsLeft)
    {
        parent::__construct($message);
    }
}
