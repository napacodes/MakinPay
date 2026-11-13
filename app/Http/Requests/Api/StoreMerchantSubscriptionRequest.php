<?php

namespace App\Http\Requests\Api;

use App\Enums\BillingCycle;
use App\Rules\PublicWebhookUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMerchantSubscriptionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'external_reference'      => ['required', 'string', 'max:100'],
            'billing_cycle'           => ['required', Rule::enum(BillingCycle::class)->except(BillingCycle::Lifetime)],
            'payment_amount'          => ['required', 'numeric', 'min:1'],
            'currency_code'           => ['required', 'string', 'max:10'],
            'customer_name'           => ['nullable', 'string', 'max:100'],
            'customer_email'          => ['required', 'email', 'max:150'],
            'description'             => ['nullable', 'string', 'max:255'],
            'success_redirect'        => ['required', 'url', 'max:255'],
            'cancel_redirect'         => ['required', 'url', 'max:255'],
            'ipn_url'                 => ['required', 'url', 'max:255', app(PublicWebhookUrl::class)],
            'starts_at'               => ['nullable', 'date', 'after_or_equal:-1 minute'],
            'metadata'                => ['nullable', 'array', 'max:50'],
            'allow_payment_methods'   => ['nullable'],
            'allow_payment_methods.*' => ['string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'external_reference.required' => __('A unique subscription reference is required.'),
            'billing_cycle.enum'          => __('Billing cycle must be daily, weekly, monthly, half_yearly, or yearly.'),
            'payment_amount.min'          => __('Subscription amount must be at least :min.'),
            'customer_email.required'     => __('Customer email is required for subscription billing.'),
            'starts_at.after_or_equal'    => __('The subscription start must be now or in the future.'),
            'metadata.max'                => __('Metadata may contain at most :max top-level entries.'),
        ];
    }
}
