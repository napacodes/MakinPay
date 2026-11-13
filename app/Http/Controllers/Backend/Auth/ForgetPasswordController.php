<?php

namespace App\Http\Controllers\Backend\Auth;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Throwable;

/**
 * Admin password reset, on the framework's `admins` password broker.
 *
 * This flow used to hand-roll everything the broker already does — and got
 * the security-sensitive parts wrong: the token was stored in plaintext (a
 * database leak meant immediate admin takeover), nothing ever checked the
 * token's age (a year-old link still worked), `exists:admins` validation let
 * anyone enumerate admin emails, and nothing throttled repeated requests.
 * The broker stores a hashed token, enforces the configured expiry and
 * per-email throttle, and deletes the token on use.
 */
class ForgetPasswordController extends Controller
{
    public function showForgetPasswordForm(): View
    {
        return view('backend.auth.forget_password');
    }

    /**
     * The response is identical whether or not the email belongs to an admin
     * — and identical again while the broker's throttle window is open — so
     * this form confirms nothing about which addresses exist.
     */
    public function submitForgetPasswordForm(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        try {
            Password::broker('admins')->sendResetLink($request->only('email'));
        } catch (Throwable $e) {
            Log::error('Admin password reset email could not be sent', [
                'error' => $e->getMessage(),
            ]);

            notifyEvs('error', __('The reset email could not be sent right now. Please try again later.'));

            return back();
        }

        notifyEvs('success', __('If that email belongs to an admin account, a password reset link has been sent.'));

        return back();
    }

    public function showResetPasswordForm(): View
    {
        return view('backend.auth.reset_password');
    }

    public function submitResetPasswordForm(Request $request): RedirectResponse
    {
        $request->validate([
            'token'                 => 'required|string',
            'email'                 => 'required|email',
            'password'              => 'required|string|min:6|confirmed',
            'password_confirmation' => 'required',
        ]);

        $status = Password::broker('admins')->reset(
            $request->only('email', 'token', 'password', 'password_confirmation'),
            function (Admin $admin, string $password): void {
                $admin->forceFill(['password' => Hash::make($password)])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            notifyEvs('success', __('Your password has been changed!'));

            return redirect()->route('admin.login');
        }

        // An unknown email and a bad token collapse into one message so this
        // form cannot be used for enumeration either.
        notifyEvs('error', __('This password reset link is invalid or has expired.'));

        return redirect()->back();
    }
}
