<?php

declare(strict_types=1);

namespace App\Contracts\PhoneVerification;

use App\Models\User;

/**
 * A phone verification provider that words a one-time sign-in code as a
 * sign-in code (GF9). A provider without it is sent the code through its
 * ordinary send(), so a custom driver keeps working unchanged.
 */
interface SendsSignInCodes
{
    public function sendSignInCode(User $user, string $code, int $minutes): void;
}
