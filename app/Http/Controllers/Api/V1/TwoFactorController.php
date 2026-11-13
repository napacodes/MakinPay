<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Api\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Services\TwoFactorService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Two-factor authentication, switched on and off from the app.
 *
 * Mirrors the website's TwoFactorController on the same TwoFactorService, so
 * a code the customer's authenticator produces is the code sign-in accepts -
 * TwoFactorChallengeService verifies with that service too.
 *
 * Two differences from the website, both deliberate:
 *
 * - SETUP ASKS FOR THE PASSWORD. App tokens never expire. Without it, a stolen
 *   token could fetch a secret, bind it to the thief's authenticator and switch
 *   two-factor on - leaving the real owner facing a challenge at sign-in that
 *   only the thief can answer, which a password reset does not clear.
 * - Setup refuses outright once two-factor is on, rather than handing back the
 *   secret of a live factor. The website's page does not print it in that
 *   state either; here the refusal is explicit.
 *
 * The secret appears in exactly one response - setup - and never in a log.
 */
class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::ok(['enabled' => (bool) $request->user()->two_factor_enabled]);
    }

    /**
     * The secret to enrol an authenticator with.
     *
     * Reused while setup is unfinished, so a retry after a dropped response
     * hands back the secret the authenticator may already hold rather than one
     * that would never match it. Handing it out switches nothing on.
     */
    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($refusal = $this->refuseForDemo($user->email)) {
            return $refusal;
        }

        $request->validate(['password' => ['required', 'string', 'current_password']]);

        if ($user->two_factor_enabled) {
            return ApiResponse::error(
                ApiErrorCode::Forbidden,
                __('Two-factor authentication is already on. Turn it off before setting it up again.'),
            );
        }

        if (blank($user->google2fa_secret)) {
            $user->google2fa_secret = $this->twoFactor->generateSecret();
            $user->save();
        }

        return ApiResponse::ok([
            'secret'      => $user->google2fa_secret,
            'otpauth_url' => $this->twoFactor->otpauthUrl($user->google2fa_secret, (string) $user->email),
        ]);
    }

    /**
     * Switch it on with a code from the authenticator that was just enrolled.
     *
     * The code proves the authenticator really holds the secret; without that,
     * a mistyped enrolment would lock the customer out at their next sign-in.
     */
    public function enable(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($refusal = $this->refuseForDemo($user->email)) {
            return $refusal;
        }

        $validated = $request->validate(['code' => ['required', 'digits:6']]);

        if (blank($user->google2fa_secret)) {
            return ApiResponse::error(
                ApiErrorCode::Forbidden,
                __('Set up two-factor authentication before switching it on.'),
            );
        }

        if (! $this->twoFactor->verifyCode((string) $user->google2fa_secret, (string) $validated['code'])) {
            throw ValidationException::withMessages(['code' => __('Invalid verification code.')]);
        }

        $user->two_factor_enabled = true;
        $user->save();

        return ApiResponse::ok(['enabled' => true]);
    }

    /**
     * Switch it off with the account password, as on the website.
     *
     * The password rather than a code, because the customer who most needs
     * this is the one who has lost the phone the authenticator was on. The
     * secret is cleared with the flag, so switching on again enrols afresh.
     */
    public function disable(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($refusal = $this->refuseForDemo($user->email)) {
            return $refusal;
        }

        $request->validate(['password' => ['required', 'string', 'current_password']]);

        $user->two_factor_enabled = false;
        $user->google2fa_secret   = null;
        $user->save();

        return ApiResponse::ok(['enabled' => false]);
    }

    private function refuseForDemo(?string $email): ?JsonResponse
    {
        if (! isDemoProtectedAccount($email)) {
            return null;
        }

        return ApiResponse::error(
            ApiErrorCode::Forbidden,
            __('Two-Factor changes are disabled for the shared demo account.'),
        );
    }
}
