<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend;

use App\Services\Kyc\KycAutomationSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Guards the handover to a provider-hosted journey.
 *
 * There are no documents to validate here — the provider collects those — so
 * the only thing to enforce is the agreement to send them, which is exactly the
 * rule the template form applies before its own submission leaves the site.
 */
class StartHostedKycRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Block the shared demo accounts before validation so the seeded KYC
     * staging data is never overwritten by an evaluator's submission.
     */
    protected function prepareForValidation(): void
    {
        if (isDemoProtectedAccount($this->user()?->email)) {
            notifyEvs('error', __('KYC submissions are disabled for the shared demo account.'));

            throw new HttpResponseException(redirect()->back());
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // A hosted journey is by definition a third-party transfer, so
            // consent is required whenever the setting asks for it.
            'verification_consent' => KycAutomationSettings::requiresConsent()
                ? ['accepted']
                : ['nullable'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'verification_consent.accepted' => __('Please agree to the identity verification statement before continuing.'),
        ];
    }
}
