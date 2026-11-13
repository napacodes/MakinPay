<?php

namespace App\Http\Controllers\Frontend\Auth\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasswordResetLinkRequest;
use App\Services\FeatureManager;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class PasswordResetLinkController extends Controller
{
    public function __construct(private readonly FeatureManager $features) {}

    public function create(): View
    {
        $this->ensureFeatureEnabled();

        return view(themedView('frontend.auth.agent.forgot-password'));
    }

    /**
     * @throws ValidationException
     */
    public function store(PasswordResetLinkRequest $request): RedirectResponse
    {
        $this->ensureFeatureEnabled();

        // Demo-mode lockdown: shared demo logins must never be reset
        // via the forgot-password flow either, otherwise an evaluator
        // could mail themselves a reset link and lock everyone else out.
        if (isDemoProtectedAccount($request->input('email'))) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => __('Password resets are disabled for the shared demo account.')]);
        }

        ResetPassword::createUrlUsing(function ($user, string $token) {
            return route('agent.password.reset', [
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ]);
        });

        // The broker mails synchronously, so a broken SMTP configuration used
        // to surface here as an unhandled 500 — the customer saw an error
        // page and no email, with nothing in the logs pointing at mail.
        try {
            $status = Password::broker('users')->sendResetLink($request->only('email'));
        } catch (Throwable $e) {
            Log::error('Password reset email could not be sent', [
                'error' => $e->getMessage(),
            ]);

            notifyEvs('error', __('The reset email could not be sent right now. Please try again later or contact support.'));

            return back()->withInput($request->only('email'))
                ->withErrors(['email' => __('The reset email could not be sent right now. Please try again later or contact support.')]);
        }

        if ($status === Password::RESET_LINK_SENT) {
            notifyEvs('success', __('Reset Link Sent Successfully'));

            return back()->with('status', __($status));
        }

        notifyEvs('error', __($status));

        return back()->withInput($request->only('email'))
            ->withErrors(['email' => __($status)]);
    }

    private function ensureFeatureEnabled(): void
    {
        $byFeatureManager = $this->features->isEnabled('agent_program');
        $bySetting        = (bool) setting('agent_program_enabled', true);

        if (! $byFeatureManager || ! $bySetting) {
            throw new NotFoundHttpException;
        }
    }
}
