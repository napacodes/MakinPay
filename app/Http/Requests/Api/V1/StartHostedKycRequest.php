<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\Api\ApiErrorCode;
use App\Http\Requests\Frontend\StartHostedKycRequest as WebsiteStartHostedKycRequest;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The website's handover rules - consent whenever the setting asks for it -
 * refusing in the app's envelope. Only the demo refusal differs: the website's
 * is a flash and a redirect back.
 */
class StartHostedKycRequest extends WebsiteStartHostedKycRequest
{
    protected function prepareForValidation(): void
    {
        if (isDemoProtectedAccount($this->user()?->email)) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::Forbidden,
                __('KYC submissions are disabled for the shared demo account.'),
            ));
        }
    }
}
