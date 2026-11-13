<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\Api\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Services\Security\PasswordChanger;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Throwable;

/**
 * Forgotten passwords, over Laravel's own broker.
 *
 * No parallel token store and no second kind of code: the row the app's reset
 * consumes is the row the web form would have consumed, so a customer can
 * start on their phone and finish in a browser, and expiry, single use and
 * throttling all behave as the framework documents.
 *
 * The emailed link still points at the web reset screen. An app that
 * registers a deep link for it can hand the token straight to reset(); one
 * that does not simply opens the browser, which works today and needs
 * nothing new.
 */
class PasswordResetController extends Controller
{
    /**
     * Ask for a reset link.
     *
     * The reply never changes with whether the address is on file. A
     * forgot-password form that answers differently for a real and an unknown
     * address is an account-enumeration endpoint with a helpful name.
     */
    public function forgot(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $email = (string) $request->input('email');

        // Shared demo logins are printed on the sign-in page. Mailing a reset
        // for one would let any visitor lock every other evaluator out.
        if (! isDemoProtectedAccount($email)) {
            $this->attemptSend($email);
        }

        return ApiResponse::ok([
            'message' => __('If that address is on file, a reset link is on its way.'),
        ]);
    }

    /**
     * Finish the reset with the token from the email.
     */
    public function reset(Request $request, PasswordChanger $passwords): JsonResponse
    {
        $request->validate([
            'token'    => ['required', 'string'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        if (isDemoProtectedAccount((string) $request->input('email'))) {
            return ApiResponse::error(
                ApiErrorCode::Forbidden,
                __('Password resets are disabled for the shared demo account.'),
            );
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request, $passwords): void {
                // Saved by the change below, in the same write.
                $user->forceFill(['remember_token' => Str::random(60)]);

                // Every device the old password reached is signed out - no
                // token is kept, because the caller holds none. A reset is
                // what someone does when they think a credential is
                // compromised, and leaving the phones holding tokens would
                // make the reset cosmetic. The change also clears an admin's
                // must_change_password, which this path used to leave set.
                $passwords->change($user, (string) $request->input('password'));

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return ApiResponse::ok(['message' => __($status)]);
        }

        // Expired, already used, forged, or for another address - all the same
        // answer, for the same reason forgot() gives one answer.
        return ApiResponse::error(ApiErrorCode::ValidationFailed, __($status), [
            'token' => [__($status)],
        ]);
    }

    /**
     * The broker mails synchronously, so a broken SMTP configuration arrives
     * here as a throwable. It is logged and swallowed: the caller is told the
     * same thing either way, because telling them the mail failed for this
     * address but not that one leaks the same fact the generic reply hides.
     */
    private function attemptSend(string $email): void
    {
        try {
            Password::broker('users')->sendResetLink(['email' => $email]);
        } catch (Throwable $e) {
            Log::error('Password reset email could not be sent', ['error' => $e->getMessage()]);
        }
    }
}
