<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend\Auth\User;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Middleware\CheckUserStatus;
use App\Http\Requests\Auth\OtpLoginSendRequest;
use App\Http\Requests\Auth\OtpLoginVerifyRequest;
use App\Services\Auth\OtpLoginService;
use App\Services\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Signing in to the user panel with a code sent by SMS (GF9).
 *
 * Two steps on one page: the number, then the code. Whatever the number,
 * the second step opens with the same sentence, so the page never says
 * whether an account has it. A correct code is treated exactly like a
 * correct password on the user login form: a suspended account is refused,
 * a merchant is sent to its own login, and two-factor authentication still
 * applies through the panel's middleware.
 */
class OtpLoginController extends Controller
{
    private const SESSION_KEY = 'otp_login.login';

    public function __construct(private readonly OtpLoginService $otp) {}

    public function create(Request $request): View
    {
        abort_unless($this->otp->isAvailable(), 404);

        return view(themedView('frontend.auth.user.otp-login'), [
            'login' => $request->session()->get(self::SESSION_KEY),
        ]);
    }

    public function send(OtpLoginSendRequest $request): RedirectResponse
    {
        abort_unless($this->otp->isAvailable(), 404);

        $this->otp->request((string) $request->validated('login'), (string) $request->ip());

        $request->session()->put(self::SESSION_KEY, (string) $request->validated('login'));

        return redirect()->route('user.login.otp')
            ->with('otp_sent', __('If this number belongs to an account with a verified phone, a sign-in code is on its way.'));
    }

    public function verify(OtpLoginVerifyRequest $request): RedirectResponse
    {
        abort_unless($this->otp->isAvailable(), 404);

        $user = $this->otp->verify(
            (string) $request->validated('login'),
            (string) $request->validated('code'),
            $request->throttleKeys(),
            (string) $request->ip(),
        );

        if ($user === null) {
            throw ValidationException::withMessages(['code' => __('The code is invalid or has expired.')]);
        }

        if ($user->isSuspended()) {
            throw ValidationException::withMessages(['code' => CheckUserStatus::suspendedMessage($user)]);
        }

        if ($user->role === UserRole::MERCHANT) {
            notifyEvs('error', __('Please use merchant login for merchant accounts.'));

            return redirect()->route('merchant.login');
        }

        Auth::login($user);

        $request->session()->regenerate();
        $request->session()->forget(self::SESSION_KEY);

        app(WalletService::class)->createDefaultWalletForUser($user);

        return redirect()->route('user.dashboard');
    }

    /**
     * Back to the first step - to ask for a new code or type another number -
     * with the number already filled in.
     */
    public function restart(Request $request): RedirectResponse
    {
        $login = $request->session()->pull(self::SESSION_KEY);

        return redirect()->route('user.login.otp')->withInput(['login' => $login]);
    }
}
