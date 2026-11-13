<?php

declare(strict_types=1);

namespace App\Http\Requests\P2P;

use App\Models\P2P\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreOrderMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $order = $this->route('order');

        $canChat = auth()->check()
            && $order instanceof Order
            && $this->user()->can('chat', $order);

        if (! $canChat) {
            return false;
        }

        return ! $this->hasFile('proof') || $this->user()->can('markPaid', $order);
    }

    public function rules(): array
    {
        return [
            'body'  => ['nullable', 'required_without:proof', 'string', 'max:1000'],
            'proof' => ['nullable', File::types(['jpg', 'jpeg', 'png', 'webp', 'pdf'])->max(5 * 1024)],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required_without' => __('Type a message or attach payment proof before sending.'),
            'body.max'              => __('Messages are limited to 1,000 characters.'),
            'proof.mimes'           => __('Payment proof must be a JPG, PNG, WebP, or PDF file.'),
            'proof.max'             => __('Payment proof must not exceed 5 MB.'),
        ];
    }
}
