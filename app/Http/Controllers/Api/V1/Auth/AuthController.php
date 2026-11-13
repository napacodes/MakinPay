<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\Api\ApiErrorCode;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Middleware\CheckUserStatus;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\OtpLoginSendRequest;
use App\Http\Requests\Api\V1\Auth\OtpLoginVerifyRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\AgentService;
use App\Services\Api\DeviceTokenService;
use App\Services\Api\TwoFactorChallengeService;
use App\Services\Auth\EmailVerificationCodeService;
use App\Services\Auth\OtpLoginService;
use App\Services\Auth\RegistrationService;
use App\Services\FeatureManager;
use App\Services\WalletService;
use App\Support\ApiResponse;
use App\Support\HomeCountry;
use App\Support\PhoneIdentifier;
use App\Support\PhoneSignIn;
use App\Support\SignInThrottle;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Signing a phone in and out.
 *
 * One endpoint for all three roles. The reply carries `user.role`, and the app
 * chooses its shell from that - three login endpoints would only mean three
 * ways to get the same answer wrong, and the web's "please use merchant
 * login" redirect exists because the web has three separate forms, not
 * because the accounts differ.
 *
 * Every refusal after a correct password names its own reason. A phone that
 * receives a bare 401 can only offer "try again", which is the wrong advice
 * for an account that is suspended, unverified or holding a temporary
 * password.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly DeviceTokenService $tokens,
        private readonly TwoFactorChallengeService $challenges,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        if ($blocked = $this->refuseWhileRateLimited($request)) {
            return $blocked;
        }

        $user = $this->attempt($request);

        if ($user === null) {
            SignInThrottle::hit($request->throttleKeys(), $this->lockSeconds());

            // Deliberately the same answer for an unknown account and a wrong
            // password: telling them apart is a way to enumerate customers.
            // The tries left are the same for both too - they count what was
            // typed, not an account - so saying so gives nothing away.
            return ApiResponse::error(ApiErrorCode::Unauthenticated, __('These credentials do not match our records.'), extra: [
                'attempts_left' => max(0, $this->attemptLimit() - SignInThrottle::attempts($request->throttleKeys())),
            ]);
        }

        // The password was right, so the attempt is not counted against the
        // limit - every branch below is a state the account is in, not a
        // guess to be slowed down.
        SignInThrottle::clearAfterSignIn($request->throttleKeys(), $user, (string) $request->ip());

        if ($refusal = $this->refuseForAccountState($user, $request->device())) {
            return $refusal;
        }

        // Mirrors the web sign-in, which has created this on the way in since
        // before the app existed. A mobile-only customer would otherwise
        // reach the wallet list with nothing in it.
        app(WalletService::class)->createDefaultWalletForUser($user);

        $token = $this->tokens->issue($user, $request->device(), $request->ip());

        // The same history the website keeps: where and on what the account
        // signed in, judged by the risk rules on the queue. Without it a
        // mobile-only customer has no last sign-in and no new-device alert.
        event(new Login('web', $user, false));

        return ApiResponse::ok([
            'token'      => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'abilities'  => $token->accessToken->abilities,
            'user'       => new UserResource($user),
        ]);
    }

    /**
     * Open an account from the app.
     *
     * The shared RegistrationService does the work the three web forms each
     * used to do for themselves, so an account opened from a phone is
     * indistinguishable from one opened in a browser.
     *
     * Which roles may self-register is the administrator's decision and is
     * read, never assumed: the agent programme has a module switch, a settings
     * switch and its own country list, and a phone must not be a way around
     * any of them.
     */
    public function register(RegisterRequest $request, RegistrationService $registration, AgentService $agents): JsonResponse
    {
        $role = $request->role();

        if ($refusal = $this->refuseUnavailableRole($role, strtoupper((string) $request->input('country')))) {
            return $refusal;
        }

        // The agent profile is built inside the registration transaction, so a
        // site with no usable currency cannot leave a half-made agent behind.
        $user = $registration->register(
            $role,
            $request->validated(),
            $request->input('referral_code'),
            $role === UserRole::AGENT
                ? fn (User $agent) => $agents->createDefaultForUser($agent)
                : null,
        );

        app(WalletService::class)->createDefaultWalletForUser($user);

        // A site that requires verification gets an account and no token: the
        // customer verifies, then signs in. Handing one out here would be a
        // way past the very gate login enforces.
        if (User::emailVerificationRequired()) {
            // A six-digit code beside the link Laravel mails, so the app can
            // finish sign-up without leaving for a mail client. A mail fault
            // must not undo the account: the resend endpoint is the retry.
            try {
                app(EmailVerificationCodeService::class)->send($user);
            } catch (\Throwable $e) {
                report($e);
            }

            return ApiResponse::created([
                'token' => null,
                'user'  => new UserResource($user),
            ]);
        }

        $token = $this->tokens->issue($user, $request->device(), $request->ip());

        return ApiResponse::created([
            'token'      => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'abilities'  => $token->accessToken->abilities,
            'user'       => new UserResource($user),
        ]);
    }

    /**
     * The administrator's switches, in the order the web controllers read
     * them. A role the site does not offer is reported as unavailable rather
     * than as a bad field: the app should hide the choice, not argue with it.
     */
    private function refuseUnavailableRole(UserRole $role, string $country): ?JsonResponse
    {
        if ($role !== UserRole::AGENT) {
            return null;
        }

        $moduleOn = app(FeatureManager::class)->isEnabled('agent_program')
            && (bool) setting('agent_program_enabled', true);

        if (! $moduleOn) {
            return ApiResponse::error(ApiErrorCode::FeatureDisabled);
        }

        if (! (bool) setting('agent_self_registration', true)) {
            return ApiResponse::error(
                ApiErrorCode::FeatureNotAccessible,
                __('Agent accounts are not open for self-registration.'),
            );
        }

        $allowed = $this->allowedAgentCountries();

        if ($allowed !== [] && ! in_array($country, $allowed, true)) {
            return ApiResponse::error(
                ApiErrorCode::FeatureNotAccessible,
                __('The Agent program is not available in :country yet.', ['country' => $country]),
            );
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function allowedAgentCountries(): array
    {
        $raw = trim((string) setting('agent_allowed_countries', ''));

        if ($raw === '') {
            return [];
        }

        return collect(explode(',', $raw))
            ->map(fn ($code): string => strtoupper(trim((string) $code)))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Trade a two-factor challenge and a code for an access token.
     *
     * Every failure answers the same way. Unknown challenge, expired, already
     * spent, wrong code, out of attempts - the app can do nothing different
     * with the distinction and an attacker could do a great deal.
     */
    /**
     * GF9: text a one-time sign-in code to the account this number names.
     *
     * Answered the same whether or not an account has the number - 202 and
     * one sentence - so the endpoint cannot be used to find customers. The
     * limits (a minute between codes, three per quarter hour per number, ten
     * an hour per address) are counted for every number alike. 404 when the
     * site does not offer sign-in by SMS code.
     */
    public function otpRequest(OtpLoginSendRequest $request, OtpLoginService $otp): JsonResponse
    {
        $otp->request((string) $request->validated('login'), (string) $request->ip());

        return ApiResponse::accepted([
            'message' => __('If this number belongs to an account with a verified phone, a sign-in code is on its way.'),
        ]);
    }

    /**
     * GF9: exchange a correct sign-in code for a token.
     *
     * A wrong code is answered like a wrong password - the same message and
     * the same shared lockout - and a correct one goes through every check a
     * correct password does, the second factor included.
     */
    public function otpVerify(OtpLoginVerifyRequest $request, OtpLoginService $otp): JsonResponse
    {
        $user = $otp->verify(
            (string) $request->validated('login'),
            (string) $request->validated('code'),
            $request->throttleKeys(),
            (string) $request->ip(),
        );

        if ($user === null) {
            return ApiResponse::error(ApiErrorCode::Unauthenticated, __('The code is invalid or has expired.'), extra: [
                'attempts_left' => max(0, $this->attemptLimit() - SignInThrottle::attempts($request->throttleKeys())),
            ]);
        }

        if ($refusal = $this->refuseForAccountState($user, $request->device())) {
            return $refusal;
        }

        app(WalletService::class)->createDefaultWalletForUser($user);

        $token = $this->tokens->issue($user, $request->device(), $request->ip());

        event(new Login('web', $user, false));

        return ApiResponse::ok([
            'token'      => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'abilities'  => $token->accessToken->abilities,
            'user'       => new UserResource($user),
        ]);
    }

    public function twoFactorVerify(Request $request): JsonResponse
    {
        $request->validate([
            'challenge_token' => ['required', 'string'],
            'code'            => ['required', 'string', 'max:12'],
        ]);

        $result = $this->challenges->consume(
            (string) $request->input('challenge_token'),
            (string) $request->input('code'),
        );

        if ($result === null) {
            return ApiResponse::error(
                ApiErrorCode::TwoFactorRequired,
                __('That code is not valid. Please sign in again.'),
            );
        }

        $user = $result['user'];

        // The account can have been suspended, or had a temporary password
        // set, between the password step and this one.
        if ($refusal = $this->refuseForAccountState($user, $result['device'], skipTwoFactor: true)) {
            return $refusal;
        }

        app(WalletService::class)->createDefaultWalletForUser($user);

        $token = $this->tokens->issue($user, $result['device'], $request->ip());

        return ApiResponse::ok([
            'token'      => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'abilities'  => $token->accessToken->abilities,
            'user'       => new UserResource($user),
        ]);
    }

    /**
     * Sign this device out, leaving the customer's other devices alone.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return ApiResponse::ok(['signed_out' => true]);
    }

    /**
     * Sign every other device out, keeping the one asking.
     *
     * The caller's own token survives on purpose: a customer tapping "sign out
     * everywhere else" after losing a phone should not also be signed out of
     * the phone in their hand.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $revoked = $this->tokens->revokeAllExcept(
            $request->user(),
            $request->user()->currentAccessToken()?->getKey(),
        );

        return ApiResponse::ok(['signed_out_devices' => $revoked]);
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::ok(new UserResource($request->user()));
    }

    /**
     * Verify the credentials without starting a session.
     *
     * `system_account_type => null` is load-bearing and copied from the web
     * form: the platform's own ledger accounts live in the users table and
     * must never be signable-in.
     */
    private function attempt(LoginRequest $request): ?User
    {
        $login    = (string) $request->input('login');
        $password = (string) $request->input('password');

        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        $user = $this->validateAgainst($field, $login, $password);

        if ($user instanceof User) {
            return $user;
        }

        /*
         * PHONE, and only after email and username have failed.
         *
         * Additive on purpose: an account that could sign in before still
         * can, including one whose USERNAME happens to be a long string of
         * digits. Resolving to `phone` up front instead would have taken that
         * username away from whoever has it, which is not a trade this is
         * worth.
         *
         * `phone` carries a unique index, so it identifies exactly one
         * account or none.
         *
         * With a home country a bare number can mean two phones; then the
         * password has to be accepted by exactly one of the two accounts
         * (PhoneSignIn). The API never recorded a Failed sign-in here and
         * still does not.
         */
        $candidates = PhoneIdentifier::candidates($login, HomeCountry::code());

        if (count($candidates) < 2) {
            return $candidates === [] ? null : $this->validateAgainst('phone', $candidates[0], $password);
        }

        return PhoneSignIn::resolve($candidates, $password)['user'];
    }

    /**
     * One credential attempt, or null.
     */
    private function validateAgainst(string $field, string $value, string $password): ?User
    {
        $valid = Auth::validate([
            $field                => $value,
            'password'            => $password,
            'system_account_type' => null,
        ]);

        if (! $valid) {
            return null;
        }

        $user = Auth::getLastAttempted();

        return $user instanceof User ? $user : null;
    }

    /**
     * States a correct password does not get past.
     *
     * Ordered by how much the answer tells the caller: suspension first,
     * because it is the only one support can lift, then the two the customer
     * can clear themselves, then the second factor.
     */
    private function refuseForAccountState(User $user, array $device = [], bool $skipTwoFactor = false): ?JsonResponse
    {
        if ($user->isSuspended()) {
            return ApiResponse::error(ApiErrorCode::AccountSuspended, CheckUserStatus::suspendedMessage($user));
        }

        if ($user->must_change_password) {
            return ApiResponse::error(ApiErrorCode::PasswordChangeRequired);
        }

        if (User::emailVerificationRequired() && $user->email_verified_at === null) {
            return ApiResponse::error(ApiErrorCode::EmailUnverified);
        }

        /*
         * A correct password on an account holding a second factor buys a
         * challenge, not a token. The challenge authorises nothing by itself
         * and expires on its own; only the exchange in twoFactorVerify()
         * turns it into access.
         */
        if ($user->two_factor_enabled && ! $skipTwoFactor) {
            $challenge = $this->challenges->issue($user, $device);

            return ApiResponse::error(ApiErrorCode::TwoFactorRequired, extra: [
                'challenge_token' => $challenge['token'],
                'expires_in'      => $challenge['expires_in'],
            ]);
        }

        return null;
    }

    private function refuseWhileRateLimited(LoginRequest $request): ?JsonResponse
    {
        if (! SignInThrottle::tooManyAttempts($request->throttleKeys(), $this->attemptLimit())) {
            return null;
        }

        event(new Lockout($request));

        $seconds = SignInThrottle::availableIn($request->throttleKeys(), $this->attemptLimit());

        return ApiResponse::error(
            ApiErrorCode::RateLimited,
            trans('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]),
            headers: ['Retry-After' => (string) $seconds],
            // In the body as well as the header: the app counts down from it.
            extra: ['retry_after' => $seconds],
        );
    }

    /**
     * The administrator's own limits, read the same way the web form reads
     * them so the two surfaces cannot disagree about how many tries an
     * account gets.
     */
    private function attemptLimit(): int
    {
        return max(3, min(10, (int) config('security.login_attempt_limit', 5)));
    }

    private function lockSeconds(): int
    {
        return max(1, min(60, (int) config('security.login_lock_minutes', 15))) * 60;
    }
}
