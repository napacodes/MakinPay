<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\Api\ApiErrorCode;
use App\Exceptions\NotifyErrorException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\Api\DeviceTokenService;
use App\Services\Auth\EmailVerificationCodeService;
use App\Services\Auth\WrongEmailCodeException;
use App\Services\PhoneVerificationService;
use App\Services\WalletService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Proving an address and a number belong to the person holding the token.
 *
 * Both flows delegate to the code the web panel already uses -
 * trySendEmailVerificationNotification on the model, PhoneVerificationService
 * for the SMS - so the cooldown, the code length, the expiry and the provider
 * are one implementation, not two that agree until one is changed.
 */
class VerificationController extends Controller
{
    /**
     * Send the verification email again.
     */
    public function resendEmail(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return ApiResponse::ok(['already_verified' => true]);
        }

        /*
         * The model sends nothing while the administrator has verification
         * switched off, and returns false for it. Reporting that as a server
         * fault would have the app telling a customer to contact support
         * about a setting that is behaving exactly as configured.
         */
        if (! User::emailVerificationRequired()) {
            return ApiResponse::error(
                ApiErrorCode::FeatureDisabled,
                __('Email verification is not required on this site.'),
            );
        }

        if (! $user->trySendEmailVerificationNotification()) {
            return ApiResponse::error(
                ApiErrorCode::ServerError,
                __('We could not send the verification email right now. Please try again in a few minutes or contact support.'),
            );
        }

        return ApiResponse::ok(['sent' => true]);
    }

    /**
     * Finish sign-up with the six-digit code that was mailed, and sign in.
     *
     * No token yet, so the address names the account. A right code marks
     * it verified and issues the token registration withheld; every wrong
     * answer says how many tries are left, and an unknown address answers
     * exactly like a wrong code, so this cannot be used to find accounts.
     */
    public function verifyEmailCode(Request $request, EmailVerificationCodeService $codes, DeviceTokenService $tokens): JsonResponse
    {
        $validated = $request->validate([
            'email'              => ['required', 'email'],
            'code'               => ['required', 'digits:6'],
            'device'             => ['sometimes', 'array'],
            'device.id'          => ['nullable', 'string', 'max:64'],
            'device.name'        => ['nullable', 'string', 'max:120'],
            'device.platform'    => ['nullable', 'string', 'max:20'],
            'device.app_version' => ['nullable', 'string', 'max:20'],
        ]);

        $user = User::query()->where('email', $validated['email'])->first();

        try {
            if ($user === null) {
                throw new NotifyErrorException(__('That code has expired. Please ask for a new one.'));
            }

            $codes->verify($user, $validated['code']);
        } catch (WrongEmailCodeException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), ['code' => [$e->getMessage()]], extra: ['attempts_left' => $e->attemptsLeft]);
        } catch (NotifyErrorException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), ['code' => [$e->getMessage()]]);
        }

        app(WalletService::class)->createDefaultWalletForUser($user);

        $device = [
            'id'          => $validated['device']['id']          ?? null,
            'name'        => $validated['device']['name']        ?? null,
            'platform'    => $validated['device']['platform']    ?? null,
            'app_version' => $validated['device']['app_version'] ?? null,
        ];
        $token = $tokens->issue($user, $device, $request->ip());

        return ApiResponse::ok([
            'token'      => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'abilities'  => $token->accessToken->abilities,
            'user'       => new UserResource($user->fresh()),
        ]);
    }

    /**
     * Mail the code again. Always answers sent: whether an address has an
     * unverified account is not something this endpoint tells a stranger.
     */
    public function resendEmailCode(Request $request, EmailVerificationCodeService $codes): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);
        $user      = User::query()->where('email', $validated['email'])->first();

        if ($user !== null && ! $user->hasVerifiedEmail() && User::emailVerificationRequired()) {
            try {
                $codes->send($user);
            } catch (NotifyErrorException $e) {
                return ApiResponse::error(ApiErrorCode::RateLimited, $e->getMessage());
            }
        }

        return ApiResponse::ok(['sent' => true]);
    }

    /**
     * Text a code to the number on the account.
     */
    public function sendPhoneCode(Request $request, PhoneVerificationService $service): JsonResponse
    {
        if ($refusal = $this->refuseForDemo($request)) {
            return $refusal;
        }

        try {
            $service->send($request->user());
        } catch (NotifyErrorException $e) {
            // Carries its own customer-facing sentence: no number on file,
            // already verified, or asked again inside the cooldown.
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage());
        }

        return ApiResponse::ok(['sent' => true]);
    }

    /**
     * Confirm the code that was texted.
     */
    public function verifyPhone(Request $request, PhoneVerificationService $service): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:12']]);

        if ($refusal = $this->refuseForDemo($request)) {
            return $refusal;
        }

        try {
            $service->verify($request->user(), (string) $request->input('code'));
        } catch (NotifyErrorException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), [
                'code' => [$e->getMessage()],
            ]);
        }

        return ApiResponse::ok(new UserResource($request->user()->fresh()));
    }

    /**
     * Shared demo logins are published on the sign-in page, and their phone
     * numbers are fictitious. Texting them would either fail or reach a real
     * stranger.
     */
    private function refuseForDemo(Request $request): ?JsonResponse
    {
        if (! isDemoProtectedAccount($request->user()->email)) {
            return null;
        }

        return ApiResponse::error(
            ApiErrorCode::Forbidden,
            __('Phone verification is disabled for the shared demo account.'),
        );
    }
}
