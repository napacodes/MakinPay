<?php

namespace App\Http\Requests\Merchant;

use App\Support\CustomerPhone;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class PaymentInitiateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Implement any authorization logic as needed.
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ref_trx'          => 'required|string|max:60',
            'currency_code'    => 'required|string',
            'payment_amount'   => 'required|numeric|min:1',
            'description'      => 'nullable|string|max:255',
            'ipn_url'          => 'required|url|max:255',
            'cancel_redirect'  => 'required|url|max:255',
            'success_redirect' => 'required|url|max:255',
            'customer_name'    => 'nullable|string|max:100',
            'customer_email'   => 'nullable|email|max:100',
            // The payer's number, for a mobile-money gateway to charge. Optional:
            // the hosted checkout asks for it when the merchant does not send it.
            'customer_phone' => CustomerPhone::rules(),
            // Optionally restrict checkout payment methods by name (single CSV string or array of strings)
            'allow_payment_methods'   => 'nullable',
            'allow_payment_methods.*' => 'string|max:100',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
