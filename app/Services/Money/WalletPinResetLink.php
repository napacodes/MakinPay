<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * The email that lets a customer clear a forgotten wallet PIN.
 *
 * Sent from the settings page and from the app, so it is built in one place:
 * two copies would sooner or later disagree about how long the link lasts or
 * where it lands. It lands on the website's confirm page in both cases - that
 * page is what actually clears the PIN, and it asks for a website sign-in when
 * the browser has none.
 *
 * Who may ask (not the shared demo account, only a confirmed address) stays
 * with each caller, because the two answer a refusal differently.
 */
final class WalletPinResetLink
{
    /**
     * Written into the email's own sentence as well, which is why that string
     * says thirty minutes rather than taking this as a parameter: its
     * translations already exist as written.
     */
    private const VALID_MINUTES = 30;

    public function send(User $user, ?string $ip = null): void
    {
        $signedUrl = URL::temporarySignedRoute(
            'user.settings.wallet-pin.reset.confirm',
            now()->addMinutes(self::VALID_MINUTES),
            ['user' => $user->id]
        );

        Mail::raw(
            __('Click the link below within 30 minutes to clear your wallet PIN, then set a new one: :url', ['url' => $signedUrl]),
            function ($message) use ($user): void {
                $message->to($user->email)
                    ->subject(__('Reset your wallet PIN'));
            }
        );

        Log::info('Wallet PIN reset link sent', [
            'user_id' => $user->id,
            'ip'      => $ip,
        ]);
    }
}
