<?php

namespace App\Http\Controllers\Frontend\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VerifyEmailController extends Controller
{
    /**
     * Mark the linked user's email address as verified.
     *
     * The route is signed and carries the user id and a hash of the address, so
     * the link proves itself without a session. That is deliberate: people open
     * the email on their phone or in a different browser from the one they
     * registered in, and an `auth`-guarded route bounced every one of them to
     * the login page. The link never signs anyone in — a forwarded email must
     * not become a way into the account — so a visitor without a session is
     * sent to sign in once the address is confirmed.
     */
    public function __invoke(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::query()->find($id);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            abort(403, __('This verification link is invalid.'));
        }

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        if (Auth::check() && (int) Auth::id() === (int) $user->getKey()) {
            return redirect()->intended(route('user.dashboard', absolute: false).'?verified=1');
        }

        notifyEvs('success', __('Your email address is verified. Sign in to continue.'));

        return redirect()->route('user.login');
    }
}
