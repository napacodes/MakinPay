<?php

namespace App\Traits;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

trait ReCaptchaValidation
{
    /**
     * Verify reCAPTCHA v2 token with Google's API.
     */
    public function verifyRecaptcha(string $token): bool
    {
        try {
            $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret'   => config('services.recaptcha.secret'),
                'response' => $token,
                'remoteip' => request()->ip(),
            ]);

            $responseData = $response->json();

            return isset($responseData['success']) && $responseData['success'];
        } catch (\Exception $e) {
            Log::error('reCAPTCHA validation failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Add reCAPTCHA v2 validation rule if configured.
     */
    public function addRecaptchaRuleIfConfigured(array $rules): array
    {
        if ($this->isRecaptchaEnabled()) {
            $rules['g-recaptcha-response'] = ['required', 'string'];
        }

        return $rules;
    }

    /**
     * Reduce a raw request value to a usable reCAPTCHA token.
     *
     * `required` is satisfied by a non-empty array, so a crafted
     * `g-recaptcha-response[]` payload can still reach code that runs
     * alongside failed rules — an `after` callback, or a controller action.
     * Anything that is not a string is not a token, so it collapses to an
     * empty string and fails verification instead of raising a TypeError.
     */
    public function normalizeRecaptchaToken(mixed $token): string
    {
        return is_string($token) ? trim($token) : '';
    }

    /**
     * Fail validation unless this request carries a token Google accepts.
     *
     * Call this from a form request's `after` callback. Keeping the check in
     * one place stops a rule and its verifier from drifting apart, which is
     * how the payment-link form ended up demanding a token it never rendered.
     */
    public function ensureRecaptchaPassed(): void
    {
        if (! $this->isRecaptchaEnabled()) {
            return;
        }

        $token = $this->normalizeRecaptchaToken($this->input('g-recaptcha-response'));

        if ($token === '' || ! $this->verifyRecaptcha($token)) {
            throw ValidationException::withMessages([
                'g-recaptcha-response' => __('Failed to verify reCAPTCHA. Please try again.'),
            ]);
        }
    }

    /**
     * Check if reCAPTCHA is enabled.
     */
    public function isRecaptchaEnabled(): bool
    {
        return config('services.recaptcha.status', false) && config('services.recaptcha.secret');
    }

    /**
     * Provide the validation message for reCAPTCHA.
     */
    public function recaptchaValidationMessages(): array
    {
        return [
            'g-recaptcha-response.required' => __('Please complete the reCAPTCHA.'),
            'g-recaptcha-response.string'   => __('Please complete the reCAPTCHA.'),
        ];
    }
}
