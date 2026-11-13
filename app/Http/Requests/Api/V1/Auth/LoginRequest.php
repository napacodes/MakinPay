<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Concerns\ReadsLoginCountry;
use App\Support\HomeCountry;
use App\Support\SignInThrottle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Credentials and the handset they came from.
 *
 * `login` is an email address, a username or a phone number, exactly as the
 * web form accepts. `phone` is unique (users_phone_unique), and the reading
 * of a typed number - with or without a home country - lives in
 * App\Support\PhoneIdentifier, shared with the web form.
 *
 * reCAPTCHA is not asked for. A phone cannot produce a token for it, and
 * pretending otherwise would either lock every app user out or reduce the
 * check to a field the client can leave blank. The brute-force budget is
 * carried entirely by the throttle instead - which is why this shares the web
 * form's throttle key rather than opening a second allowance.
 */
class LoginRequest extends FormRequest
{
    use ReadsLoginCountry;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'login'              => ['required', 'string', 'max:255'],
            'password'           => ['required', 'string'],
            'device'             => ['sometimes', 'array'],
            'device.id'          => ['nullable', 'string', 'max:64'],
            'device.name'        => ['nullable', 'string', 'max:120'],
            'device.platform'    => ['nullable', 'string', 'max:20'],
            'device.app_version' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'login.required'    => __('Login field is required.'),
            'password.required' => __('Password field is required.'),
        ];
    }

    /**
     * @return array{id: ?string, name: ?string, platform: ?string, app_version: ?string}
     */
    public function device(): array
    {
        return [
            'id'          => $this->input('device.id'),
            'name'        => $this->input('device.name'),
            'platform'    => $this->input('device.platform'),
            'app_version' => $this->input('device.app_version'),
        ];
    }

    /**
     * Shared with the web sign-in form on purpose.
     *
     * Keying the two surfaces separately would hand an attacker twice the
     * attempts for the same account simply by alternating between them.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('login')).'|'.$this->ip());
    }

    /**
     * The login key plus one key per phone number the sign-in checks - the
     * same set the web form counts against (SignInThrottle).
     *
     * @return list<string>
     */
    public function throttleKeys(): array
    {
        return SignInThrottle::keys($this->throttleKey(), $this->string('login')->toString(), HomeCountry::code(), (string) $this->ip());
    }
}
