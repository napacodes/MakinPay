<?php

namespace App\Http\Requests\Frontend;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Mirrors the shape the browser hands back from
     * `PushManager.subscribe()` once it is JSON-serialised.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'endpoint'         => ['required', 'string', 'url', 'max:500'],
            'keys'             => ['required', 'array'],
            'keys.p256dh'      => ['required', 'string', 'max:255'],
            'keys.auth'        => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'endpoint.required'    => __('The push subscription endpoint is missing.'),
            'endpoint.url'         => __('The push subscription endpoint must be a valid URL.'),
            'endpoint.max'         => __('The push subscription endpoint may not be greater than :max characters.'),
            'keys.required'        => __('The push subscription keys are missing.'),
            'keys.p256dh.required' => __('The push subscription public key is missing.'),
            'keys.auth.required'   => __('The push subscription auth token is missing.'),
        ];
    }
}
