<?php

namespace App\Http\Requests\WithdrawMethod;

use App\Enums\FixPctType;
use App\Enums\MethodType;
use App\Http\Requests\Concerns\ValidatesChargeComponents;
use App\Models\PaymentGateway;
use App\Models\WithdrawMethod;
use App\Services\MassPayment\MassPayoutMethods;
use App\Services\Payment\Payout\PayoutGatewayFactory;
use App\Support\WithdrawFieldNormalizer;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreUpdateRequest extends FormRequest
{
    use ValidatesChargeComponents;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isManual = $this->input('type') === MethodType::MANUAL->value;

        return [
            'logo'               => 'sometimes|required_if:type,manual|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'type'               => 'required',
            'name'               => 'required',
            'payment_gateway_id' => $isManual ? ['nullable'] : ['required', 'integer', 'exists:payment_gateways,id'],
            // Manual methods are identified by their unique method_code, allowing
            // unlimited manual methods per currency. Automatic methods derive their
            // code from the gateway and get a unique suffix when the base code exists.
            'method_code' => $isManual
                ? ['required', Rule::unique('withdraw_methods', 'method_code')->ignore($this->route('method'))]
                : ['nullable'],
            'currency'        => 'required',
            'currency_symbol' => 'required',

            // Legacy single amount + type. No longer required — the form now
            // posts the fixed/percent pair — but still accepted for one
            // release so a cached Blade or an external post does not 422.
            'user_charge'          => 'nullable|numeric|min:0',
            'user_charge_type'     => 'nullable|in:'.FixPctType::FIXED->value.','.FixPctType::PERCENT->value,
            'merchant_charge'      => 'nullable|numeric|min:0',
            'merchant_charge_type' => 'nullable|in:'.FixPctType::FIXED->value.','.FixPctType::PERCENT->value,
            'agent_charge'         => 'nullable|numeric|min:0',
            'agent_charge_type'    => 'nullable|in:'.FixPctType::FIXED->value.','.FixPctType::PERCENT->value,
            'charge'               => 'nullable|numeric|min:0',
            'charge_type'          => 'nullable|in:'.FixPctType::FIXED->value.','.FixPctType::PERCENT->value,

            ...$this->chargeComponentRules(),

            'conversion_rate_live' => 'boolean',
            'conversion_rate'      => 'required_if:conversion_rate_live,0',
            'min_withdraw'         => 'required',
            'max_withdraw'         => 'required',
            // Stated in the method's own currency, unlike the two above.
            'provider_min_amount' => ['nullable', 'numeric', 'min:0'],
            'provider_max_amount' => ['nullable', 'numeric', 'min:0', Rule::when($this->filled('provider_min_amount'), ['gte:provider_min_amount'])],
            'fields'              => 'required_if:type,manual',
            'process_time_value'  => 'required_if:type,manual',
            'process_time_unit'   => 'required_if:type,manual',
            'status'              => 'boolean',
            // Opt-in for mass payouts. Absent from the post means "leave it
            // as it is", so an older cached form never clears it; blank means
            // "not offered".
            'mass_payout_driver' => ['nullable', 'string', Rule::in(PayoutGatewayFactory::MASS_PAYOUT_DRIVERS)],
        ];
    }

    /**
     * A mass payout driver may only be set on a method whose shape suits it,
     * judged on exactly what the controller will save: the posted type, the
     * posted gateway (or the method's own), and the posted fields or else the
     * gateway's withdraw fields, falling back to the single account field.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $driver = $this->input('mass_payout_driver');

                if (! is_string($driver) || $driver === '' || $validator->errors()->hasAny(['mass_payout_driver', 'type', 'payment_gateway_id'])) {
                    return;
                }

                $issues = app(MassPayoutMethods::class)->compatibilityIssues(
                    $driver,
                    (string) $this->input('type'),
                    $this->effectiveGateway()?->code,
                    $this->effectiveFields(),
                );

                foreach ($issues as $issue) {
                    $validator->errors()->add('mass_payout_driver', $issue);
                }
            },
        ];
    }

    private function effectiveGateway(): ?PaymentGateway
    {
        if ($this->filled('payment_gateway_id')) {
            return PaymentGateway::query()->find((int) $this->input('payment_gateway_id'));
        }

        $method = $this->route('method');

        return $method === null ? null : WithdrawMethod::query()->find((int) $method)?->paymentGateway;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function effectiveFields(): array
    {
        $fields = $this->filled('fields')
            ? WithdrawFieldNormalizer::normalize($this->input('fields'))
            : WithdrawFieldNormalizer::normalize($this->effectiveGateway()?->withdraw_field);

        return $fields === [] ? [['name' => 'account', 'type' => 'text', 'validation' => 'required']] : $fields;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'method_code.unique' => __('This method code is already in use. Please choose a unique method code.'),
            ...$this->chargeComponentMessages(),
        ];
    }
}
