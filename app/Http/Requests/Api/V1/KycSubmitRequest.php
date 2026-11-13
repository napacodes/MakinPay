<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\Api\ApiErrorCode;
use App\Http\Requests\Frontend\KycSubmitRequest as WebsiteKycSubmitRequest;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The website's KYC form rules, refusing in the app's envelope.
 *
 * Everything that decides anything - the template for this role, a rule per
 * field type, the upload types and size, the consent - is inherited, so the app
 * cannot accept a submission the website would refuse. Only the demo refusal is
 * the app's own: the website answers it with a flash and a redirect back, which
 * to an app is a 302 to nowhere.
 */
class KycSubmitRequest extends WebsiteKycSubmitRequest
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
