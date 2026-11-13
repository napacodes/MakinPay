<?php

declare(strict_types=1);

namespace App\Http\Requests\PaymentLink;

use App\Enums\MethodType;
use App\Support\CustomerPhone;
use App\Traits\ReCaptchaValidation;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PayPaymentLinkRequest extends FormRequest
{
    use ReCaptchaValidation;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isWalletPayment = $this->isWalletPayment();

        $rules = [
            'amount'          => ['required', 'numeric', 'min:0.01'],
            'selected_method' => ['required', 'string', 'max:64'],
            'wallet_id'       => [Rule::requiredIf($isWalletPayment && ! $this->boolean('use_account')), 'nullable', 'string', 'max:64'],
            'pin'             => [Rule::requiredIf($isWalletPayment), 'nullable', 'digits:6'],
            'use_account'     => ['nullable', 'boolean'],
            'customer_name'   => ['nullable', 'string', 'max:120'],
            'customer_email'  => ['nullable', 'email', 'max:160'],
            'customer_phone'  => CustomerPhone::rules(),
        ];

        if ($this->requiresRecaptcha()) {
            $rules = $this->addRecaptchaRuleIfConfigured($rules);
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Verify the reCAPTCHA token live, mirroring LoginRequest. This
            // gate must match rules() exactly: demanding a token the form was
            // never asked to render blocks the payment outright.
            if (! $this->requiresRecaptcha() || ! $this->isRecaptchaEnabled()) {
                return;
            }

            $token = $this->normalizeRecaptchaToken($this->input('g-recaptcha-response'));

            if ($token === '' || ! $this->verifyRecaptcha($token)) {
                throw ValidationException::withMessages([
                    'g-recaptcha-response' => __('Failed to verify reCAPTCHA. Please try again.'),
                ]);
            }
        });
    }

    /**
     * Only the guest wallet-PIN path is captcha-gated. Gateway checkouts are
     * bot-guarded by the provider, and authenticated payers (use_account=1)
     * already passed login + 2FA + status checks.
     */
    public function requiresRecaptcha(): bool
    {
        return $this->isWalletPayment() && ! $this->boolean('use_account');
    }

    private function isWalletPayment(): bool
    {
        return $this->input('selected_method', MethodType::SYSTEM->value) === MethodType::SYSTEM->value;
    }

    public function messages(): array
    {
        return array_merge([
            'amount.required'            => __('Please enter the amount you want to pay.'),
            'amount.min'                 => __('Amount must be greater than zero.'),
            'selected_method.required'   => __('Please choose a payment method.'),
            'wallet_id.required'         => __('Please enter the wallet ID you want to pay from.'),
            'wallet_id.required_without' => __('Please enter the wallet ID you want to pay from.'),
            'pin.required'               => __('Please enter your Wallet PIN.'),
            'pin.digits'                 => __('The Wallet PIN must be exactly 6 digits.'),
        ], $this->recaptchaValidationMessages());
    }
}
