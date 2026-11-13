<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend\Activity;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding one address to the deny list.
 */
class StoreIpBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ip_address' => ['required', 'string', 'ip'],
            'reason'     => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ip_address.ip' => __('Enter a single IPv4 or IPv6 address.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['ip_address' => trim((string) $this->input('ip_address'))]);
    }
}
