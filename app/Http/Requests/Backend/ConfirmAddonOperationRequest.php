<?php

declare(strict_types=1);

namespace App\Http\Requests\Backend;

use App\Services\TwoFactorService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Proof that the administrator at the keyboard is the one signed in, asked
 * before anything that runs a package's code or drops its tables: the
 * current password, and the authenticator code when this admin uses 2FA.
 */
class ConfirmAddonOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'current_password:admin'],
            'otp'      => [$this->needsOneTimeCode() ? 'required' : 'nullable', 'digits:6'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->needsOneTimeCode() || $validator->errors()->has('otp')) {
                    return;
                }

                $admin = $this->user('admin');

                if (! app(TwoFactorService::class)->verifyCode((string) $admin->google2fa_secret, (string) $this->input('otp'))) {
                    $validator->errors()->add('otp', __('That code is not valid. Use the current code from your authenticator app.'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.required'         => __('Enter your password to confirm.'),
            'password.current_password' => __('That password is not correct.'),
            'otp.required'              => __('Enter the code from your authenticator app.'),
            'otp.digits'                => __('The code is 6 digits.'),
        ];
    }

    protected function needsOneTimeCode(): bool
    {
        $admin = $this->user('admin');

        return $admin !== null && (bool) $admin->two_factor_enabled && filled($admin->google2fa_secret);
    }
}
