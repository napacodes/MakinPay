<?php

declare(strict_types=1);

namespace App\Http\Requests\MassPayment;

use App\Services\MassPayment\MassPaymentConfirmation;
use App\Services\MassPayment\MassPaymentService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * A customer confirms the draft they reviewed.
 *
 * The form carries the preview's fingerprint and total as hidden fields, so
 * MassPaymentService can refuse a batch that changed after the page was
 * drawn, and the customer's two explicit opt-ins: skipping the invalid rows
 * and acknowledging the duplicate warnings. Both default to no.
 *
 * The wallet PIN is demanded here only from a customer who has set one, so
 * the refusal reads as a field error; the service checks it again (and is
 * what enforces it) before it even looks the batch up.
 *
 * A refused form goes back with what was submitted, except the PIN: the
 * session would otherwise hold it as plain old input until the next request.
 */
class ConfirmMassPaymentRequest extends FormRequest
{
    /** Never flashed back as old input. */
    public const NEVER_FLASHED = ['wallet_pin'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'wallet_pin' => [
                Rule::requiredIf(fn (): bool => (bool) $this->user()?->hasWalletPin()),
                'nullable',
                'digits:6',
            ],
            'fingerprint'          => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'expected_total'       => ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,18})?$/'],
            'skip_invalid'         => ['nullable', 'boolean'],
            'acknowledge_warnings' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'wallet_pin.required'          => __('Your wallet PIN is required for this action.'),
            'wallet_pin.digits'            => __('The wallet PIN must be 6 digits.'),
            'fingerprint.required'         => __('Reload the page to review the batch again.'),
            'fingerprint.string'           => __('Reload the page to review the batch again.'),
            'fingerprint.regex'            => __('Reload the page to review the batch again.'),
            'expected_total.required'      => __('Reload the page to see the current total.'),
            'expected_total.string'        => __('Reload the page to see the current total.'),
            'expected_total.regex'         => __('Reload the page to see the current total.'),
            'skip_invalid.boolean'         => __('Reload the page and try again.'),
            'acknowledge_warnings.boolean' => __('Reload the page and try again.'),
        ];
    }

    /** What the customer confirmed, for MassPaymentService::confirm(). */
    public function confirmation(): MassPaymentConfirmation
    {
        $validated = $this->validated();
        $pin       = $validated['wallet_pin'] ?? null;

        return new MassPaymentConfirmation(
            pin: $pin === null ? null : (string) $pin,
            fingerprint: (string) $validated['fingerprint'],
            expectedTotal: (string) $validated['expected_total'],
            skipInvalid: $this->boolean('skip_invalid'),
            acknowledgeWarnings: $this->boolean('acknowledge_warnings'),
            channel: $this->channel(),
        );
    }

    /** The surface the confirmation came through. */
    protected function channel(): string
    {
        return MassPaymentService::CHANNEL_WEB;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            redirect()->to($this->getRedirectUrl())
                ->withInput($this->except(self::NEVER_FLASHED))
                ->withErrors($validator->errors(), $this->errorBag)
        );
    }
}
