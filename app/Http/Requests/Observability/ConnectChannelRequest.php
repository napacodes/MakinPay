<?php

namespace App\Http\Requests\Observability;

use App\Services\Observability\AlertRouter;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the credentials for one notification channel.
 *
 * The rules are read from the adapter rather than written out here, so a
 * channel can never present a form field the adapter does not read, and a
 * required credential cannot be forgotten in two places at once.
 */
class ConnectChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin')?->can('observability-manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $adapter = app(AlertRouter::class)->adapter((string) $this->route('key'));

        if ($adapter === null) {
            return [];
        }

        $rules = [];

        foreach ($adapter->credentialFields() as $field => $definition) {
            $rules['credentials.'.$field] = $definition['rule'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $adapter = app(AlertRouter::class)->adapter((string) $this->route('key'));

        if ($adapter === null) {
            return [];
        }

        $attributes = [];

        foreach ($adapter->credentialFields() as $field => $definition) {
            $attributes['credentials.'.$field] = mb_strtolower($definition['label']);
        }

        return $attributes;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'credentials.*.required' => __('The :attribute is needed before this channel can send anything.'),
            'credentials.*.url'      => __('The :attribute must be a valid URL.'),
        ];
    }

    /**
     * Only the fields the adapter declared. Anything else the form posted is
     * discarded rather than stored, so a tampered form cannot smuggle extra
     * keys into the encrypted credential blob.
     *
     * @return array<string, string>
     */
    public function credentials(): array
    {
        $adapter = app(AlertRouter::class)->adapter((string) $this->route('key'));

        if ($adapter === null) {
            return [];
        }

        $submitted = (array) $this->validated('credentials', []);

        return array_intersect_key($submitted, $adapter->credentialFields());
    }
}
