<?php

namespace App\Http\Requests\VirtualCard;

use App\Enums\VirtualCard\CardholderStatus;
use App\Enums\VirtualCard\VirtualCardNetwork;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVirtualCardRequest extends FormRequest
{
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
        $userId = $this->user()?->id;

        return [
            'cardholder_id' => [
                'required',
                'integer',
                Rule::exists('cardholders', 'id')->where(
                    fn ($query) => $query
                        ->where('user_id', $userId)
                        ->where('status', CardholderStatus::APPROVED->value)
                ),
            ],
            'wallet_id' => [
                'required',
                'integer',
                Rule::exists('wallets', 'id')->where(
                    fn ($query) => $query->where('user_id', $userId)
                ),
            ],
            'network'             => ['required', Rule::enum(VirtualCardNetwork::class)],
            'initial_load_amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'theme'               => ['nullable', 'string', Rule::in(['midnight', 'ocean', 'graphite', 'emerald', 'violet'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cardholder_id.exists' => __('Select one of your approved cardholders.'),
            'wallet_id.exists'     => __('Select one of your wallets.'),
        ];
    }
}
