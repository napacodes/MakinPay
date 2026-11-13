<?php

namespace App\Http\Requests\Auth;

use App\Http\Middleware\CheckUserStatus;
use App\Http\Requests\Concerns\ReadsLoginCountry;
use App\Models\User;
use App\Support\HomeCountry;
use App\Support\PhoneIdentifier;
use App\Support\PhoneSignIn;
use App\Support\SignInThrottle;
use App\Traits\ReCaptchaValidation;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    use ReadsLoginCountry;
    use ReCaptchaValidation;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'login'    => ['required', 'string'],
            'password' => ['required', 'string'],
        ];

        // Add reCAPTCHA rule if enabled in config
        return $this->addRecaptchaRuleIfConfigured($rules);
    }

    public function messages(): array
    {
        return array_merge([
            'login.required'    => 'Login field is required.',
            'password.required' => 'Password field is required.',
        ], $this->recaptchaValidationMessages());
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // ✅ Verify reCAPTCHA token if enabled
        if ($this->isRecaptchaEnabled()) {
            $token = $this->normalizeRecaptchaToken($this->input('g-recaptcha-response'));
            if ($token === '' || ! $this->verifyRecaptcha($token)) {
                throw ValidationException::withMessages([
                    'g-recaptcha-response' => __('Failed to verify reCAPTCHA. Please try again.'),
                ]);
            }
        }

        $loginInput = (string) $this->input('login');
        $loginField = filter_var($loginInput, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        if (! $this->attemptWith($loginField, $loginInput) && ! $this->attemptWithPhone($loginInput)) {
            SignInThrottle::hit($this->throttleKeys(), $this->loginLockSeconds());

            throw ValidationException::withMessages([
                'login' => trans('auth.failed'),
            ]);
        }

        /*
         * The credentials were right, but an admin has suspended the account:
         * refuse here, before a session is handed out, and say until when.
         * The attempt is not counted against the rate limit — the password
         * was correct.
         */
        $user = Auth::user();

        if ($user instanceof User && $user->isSuspended()) {
            Auth::logout();

            throw ValidationException::withMessages([
                'login' => CheckUserStatus::suspendedMessage($user),
            ]);
        }

        SignInThrottle::clearAfterSignIn($this->throttleKeys(), $user instanceof User ? $user : null, (string) $this->ip());
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! SignInThrottle::tooManyAttempts($this->throttleKeys(), $this->loginAttemptLimit())) {
            return;
        }

        event(new Lockout($this));

        $seconds = SignInThrottle::availableIn($this->throttleKeys(), $this->loginAttemptLimit());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * The login string and the IP, as it always was - shared with the mobile
     * API's sign-in, byte for byte.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('login')).'|'.$this->ip());
    }

    /**
     * The login key plus one key per phone number the sign-in checks, so
     * every spelling of one number shares a budget (SignInThrottle).
     *
     * @return list<string>
     */
    public function throttleKeys(): array
    {
        return SignInThrottle::keys($this->throttleKey(), $this->string('login')->toString(), HomeCountry::code(), (string) $this->ip());
    }

    private function loginAttemptLimit(): int
    {
        return max(3, min(10, (int) config('security.login_attempt_limit', 5)));
    }

    private function loginLockSeconds(): int
    {
        return max(1, min(60, (int) config('security.login_lock_minutes', 15))) * 60;
    }

    /**
     * One credential attempt against a named column.
     */
    private function attemptWith(string $field, string $value): bool
    {
        return Auth::attempt([
            $field                => $value,
            'password'            => (string) $this->input('password'),
            'system_account_type' => null,
        ], $this->boolean('remember'));
    }

    /**
     * PHONE, and only after email and username have failed.
     *
     * Additive on purpose: an account that could sign in before still can,
     * including one whose USERNAME happens to be a long string of digits.
     * Resolving to `phone` up front on the shape of the input would have
     * taken that username away from whoever has it - a silent lockout for a
     * customer who changed nothing.
     *
     * `phone` carries a unique index, so it identifies exactly one account or
     * none, and the rule for reading a typed string as a number lives in
     * PhoneIdentifier so this form and the mobile API cannot drift apart.
     *
     * WITH A HOME COUNTRY a bare number can mean two phones, and PhoneSignIn
     * signs in only when exactly one of the two accounts takes the password.
     * The winner still goes through the ordinary attempt below, so
     * remember-me, rehashing and the Login event are what they always were.
     * A refusal records a Failed sign-in against each account the number
     * could have meant, because validate() fires nothing and the risk panel
     * counts those rows; an account whose password was right gets none.
     *
     * Swapping the two attempts survives mutation, and honestly: BOTH always
     * run here, so the order only decides which account wins when one
     * customer's phone equals another's all-digit username. The API resolves
     * a single field instead, where the order is the whole behaviour and the
     * mutation is caught. Kept in this order to match it.
     */
    private function attemptWithPhone(string $login): bool
    {
        $candidates = PhoneIdentifier::candidates($login, HomeCountry::code());

        if (count($candidates) < 2) {
            return $candidates !== [] && $this->attemptWith('phone', $candidates[0]);
        }

        $outcome = PhoneSignIn::resolve($candidates, (string) $this->input('password'));

        if ($outcome['phone'] !== null) {
            return $this->attemptWith('phone', $outcome['phone']);
        }

        $this->recordRefusedPhoneSignIn($outcome);

        return false;
    }

    /**
     * The Failed events one ordinary attempt would have fired.
     *
     * One per account the number could have meant; one with no account when
     * it matched nobody, as an attempt on an unknown number records today;
     * none when two accounts both took the password, which is not a wrong
     * password and is logged by PhoneSignIn instead. The credentials never
     * carry the password.
     *
     * @param array{accepted: int, refused: list<array{phone: string, account: ?User}>} $outcome
     */
    private function recordRefusedPhoneSignIn(array $outcome): void
    {
        if ($outcome['accepted'] > 0) {
            return;
        }

        $accounts = array_values(array_filter($outcome['refused'], fn (array $refusal): bool => $refusal['account'] !== null));

        if ($accounts === []) {
            event(new Failed(PhoneSignIn::GUARD, null, ['phone' => $outcome['refused'][0]['phone'] ?? null, 'system_account_type' => null]));

            return;
        }

        foreach ($accounts as $refusal) {
            event(new Failed(PhoneSignIn::GUARD, $refusal['account'], ['phone' => $refusal['phone'], 'system_account_type' => null]));
        }
    }
}
