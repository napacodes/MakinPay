<?php

namespace App\Http\Requests\Frontend;

use App\Models\KycTemplate;
use App\Services\Kyc\KycAutomationSettings;
use App\Services\Media\MediaSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class KycSubmitRequest extends FormRequest
{
    private ?KycTemplate $template = null;

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
     * Resolve the active KYC template for the authenticated user's role.
     */
    public function kycTemplate(): KycTemplate
    {
        return $this->template ??= KycTemplate::active()
            ->where('id', $this->input('template_id'))
            ->whereJsonContains('applicable_to', $this->user()->role->value)
            ->firstOrFail();
    }

    /**
     * Build dynamic rules from the template's field definitions.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // Same source the uploader's own guard reads, so a file the form
        // accepts can never be rejected afterwards by the trait.
        $maxKilobytes  = MediaSettings::effectiveMaxUploadSizeKb();
        $identityTypes = MediaSettings::customerSafe(MediaSettings::identityDocumentExtensions());

        $rules = [
            'template_id' => ['required', 'integer'],
            'note'        => ['nullable', 'string', 'max:1000'],
            // Documents only leave the site when automation is on, so that is
            // the only time there is anything to consent to.
            'verification_consent' => KycAutomationSettings::requiresConsent()
                ? ['accepted']
                : ['nullable'],
        ];

        foreach ($this->kycTemplate()->fields as $field) {
            $fieldKey   = "credentials.{$field['label']}";
            $isRequired = isset($field['required']) && ($field['required'] === 'true' || $field['required'] === true);
            $base       = $isRequired ? ['required'] : ['nullable'];

            /*
             * Every type the builder offers has an arm here. It matters:
             * anything unmatched falls through to the string arm, so a date
             * the applicant typed as "next tuesday" and a country that is
             * not a country both stored happily and reached the reviewer as
             * a KYC answer.
             */
            $rules[$fieldKey] = match ($field['type'] ?? 'text') {
                'file'    => array_merge($base, ['file', 'mimes:'.implode(',', $identityTypes), "max:{$maxKilobytes}"]),
                'number'  => array_merge($base, ['numeric']),
                'date'    => array_merge($base, ['date']),
                'country' => array_merge($base, ['string', Rule::in(array_column(getCountries(), 'code'))]),
                default   => array_merge($base, ['string', 'max:1000']),
            };
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'credentials.*.required' => __('This field is required.'),
            'credentials.*.mimes'    => __('Upload one of these file types: :types.', [
                'types' => strtoupper(implode(', ', MediaSettings::identityDocumentExtensions())),
            ]),
            'credentials.*.max' => __('That file is larger than the :size MB upload limit.', [
                'size' => MediaSettings::effectiveMaxUploadSizeMb(),
            ]),
            'credentials.*.numeric'         => __('This field must be a number.'),
            'verification_consent.accepted' => __('Please agree to the identity verification statement before submitting.'),
        ];
    }
}
