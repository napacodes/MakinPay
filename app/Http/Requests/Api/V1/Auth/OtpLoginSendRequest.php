<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Auth\OtpLoginSendRequest as WebOtpLoginSendRequest;

/**
 * The app's request for a sign-in code (GF9): the web form's field, without
 * the reCAPTCHA a phone cannot produce. The per-number and per-address limits
 * carry the budget, exactly as for the web.
 */
class OtpLoginSendRequest extends WebOtpLoginSendRequest
{
    protected function wantsRecaptcha(): bool
    {
        return false;
    }
}
