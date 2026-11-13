<?php

namespace App\Http\Requests\Backend;

use App\Enums\KycRejectionReason;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KycReviewActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'submission_id' => ['required', 'integer', 'exists:kyc_submissions,id'],
            'action'        => ['required', Rule::in(['approve', 'reject'])],

            /*
             * Required to reject, and only to reject.
             *
             * The rejection notification interpolates a rejection reason, and
             * remarks are optional — so a reviewer who clicked Reject without
             * typing anything sent the applicant a template with a blank where
             * the reason should be, and no way to know what to fix.
             */
            'reason_code' => [
                Rule::requiredIf(fn (): bool => $this->input('action') === 'reject'),
                'nullable',
                Rule::enum(KycRejectionReason::class),
            ],

            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason_code.required' => __('Choose why this submission is being rejected. The applicant is told the reason.'),
            'reason_code.enum'     => __('That is not a rejection reason this panel recognises.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reason_code' => __('rejection reason'),
        ];
    }
}
