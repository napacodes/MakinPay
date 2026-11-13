<?php

namespace App\Rules;

use App\Services\PublicWebhookUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class PublicWebhookUrl implements ValidationRule
{
    public function __construct(private readonly PublicWebhookUrlGuard $guard) {}

    /**
     * Run the validation rule.
     *
     * @param Closure(string, ?string=): PotentiallyTranslatedString $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->guard->hasSafeShape($value)) {
            $fail(__('The :attribute must be a public HTTPS webhook URL without embedded credentials.'));
        }
    }
}
