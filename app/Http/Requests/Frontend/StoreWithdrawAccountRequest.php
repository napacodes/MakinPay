<?php

namespace App\Http\Requests\Frontend;

use App\Models\WithdrawMethod;
use App\Support\CryptoAddressValidator;
use App\Support\WithdrawFieldNormalizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWithdrawAccountRequest extends FormRequest
{
    private ?WithdrawMethod $selectedWithdrawMethod = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge([
            'currency' => [
                'required',
                'string',
                Rule::in($this->activeWalletCurrencyCodes()),
            ],
            'method_id' => [
                'required',
                'integer',
                Rule::exists('withdraw_methods', 'id')->where('status', true),
                function (string $attribute, mixed $value, Closure $fail): void {
                    $method   = $this->withdrawMethod();
                    $currency = $this->input('currency');

                    if ($method && $currency && $method->currency !== $currency) {
                        $fail(__('Selected payment method is not available for this currency.'));
                    }
                },
            ],
            'account_name' => ['required', 'string', 'max:255'],
        ], WithdrawFieldNormalizer::rules($this->withdrawMethod()?->fields ?? []), $this->cryptoAddressRules());
    }

    /**
     * Validate crypto wallet addresses against the selected network. The
     * network comes from the account's `network` field when present,
     * otherwise from the method code (e.g. `usdt-trc20-manual`).
     *
     * @return array<string, array<int, mixed>>
     */
    private function cryptoAddressRules(): array
    {
        $method = $this->withdrawMethod();

        if (! $method) {
            return [];
        }

        $hasAddressField = collect(WithdrawFieldNormalizer::normalize($method->fields))
            ->contains(fn (array $field): bool => $field['name'] === 'wallet_address');

        if (! $hasAddressField) {
            return [];
        }

        return [
            'credentials.wallet_address' => [
                function (string $attribute, mixed $value, Closure $fail) use ($method): void {
                    $network = CryptoAddressValidator::detectNetwork(
                        $this->input('credentials.network') ?? $method->method_code
                    );

                    if ($network === null) {
                        return;
                    }

                    if (! is_string($value) || ! CryptoAddressValidator::isValid($network, $value)) {
                        $fail(__('The wallet address is not a valid :network address.', [
                            'network' => CryptoAddressValidator::labelFor($network),
                        ]));
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'currency.required'     => __('Please select a currency first.'),
            'method_id.required'    => __('Please select a payment method.'),
            'account_name.required' => __('Please enter an account name.'),
        ];
    }

    public function withdrawMethod(): ?WithdrawMethod
    {
        if ($this->selectedWithdrawMethod instanceof WithdrawMethod) {
            return $this->selectedWithdrawMethod;
        }

        if (! $this->filled('method_id')) {
            return null;
        }

        $this->selectedWithdrawMethod = WithdrawMethod::active()->find((int) $this->input('method_id'));

        return $this->selectedWithdrawMethod;
    }

    /**
     * @return array<int, string>
     */
    private function activeWalletCurrencyCodes(): array
    {
        return $this->user()?->activeWallets()
            ->pluck('currency.code')
            ->filter()
            ->unique()
            ->values()
            ->all() ?? [];
    }
}
