<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\VirtualCard\VirtualCardStatus;
use App\Models\LoginActivity;
use App\Models\User;
use App\Models\VirtualCard;
use App\Services\Api\DeviceTokenService;
use App\Services\Money\WalletPinResetLink;
use App\Services\VirtualCard\VirtualCardControls;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * The panic button: a customer who has lost their phone or suspects fraud
 * freezes every live card, signs every other device out and gets a PIN
 * reset link in one tap. Each step is its own attempt, so a card provider
 * that refuses does not stop the sessions from going.
 */
final class AccountLockdown
{
    public function __construct(
        private readonly VirtualCardControls $cards,
        private readonly DeviceTokenService $tokens,
        private readonly WalletPinResetLink $pinReset,
    ) {}

    /**
     * @return array{cards_frozen: int, cards_failed: int, sessions_revoked: int, web_sessions_ended: int, pin_reset_sent: bool}
     */
    public function run(User $user, ?PersonalAccessToken $keep, ?string $ip = null): array
    {
        $frozen = 0;
        $failed = 0;
        $live   = VirtualCard::query()
            ->where('user_id', $user->id)
            ->where('status', VirtualCardStatus::Active->value)
            ->with('provider')
            ->get();

        foreach ($live as $card) {
            try {
                $this->cards->freeze($card, 'suspicious', 'indefinite');
                $frozen++;
            } catch (Throwable $exception) {
                $failed++;
                Log::warning('Lockdown could not freeze a card', ['card_id' => $card->id, 'reason' => $exception->getMessage()]);
            }
        }

        $revoked = $this->tokens->revokeAllExcept($user, $keep?->getKey());
        $web     = $this->endWebSessions($user);

        $pinReset = false;
        if ($user->email_verified_at !== null && ! isDemoProtectedAccount($user->email)) {
            try {
                $this->pinReset->send($user, $ip);
                $pinReset = true;
            } catch (Throwable $exception) {
                Log::warning('Lockdown could not send the PIN reset link', ['user_id' => $user->id, 'reason' => $exception->getMessage()]);
            }
        }

        Log::info('Account lockdown', ['user_id' => $user->id, 'cards_frozen' => $frozen, 'sessions_revoked' => $revoked, 'web_sessions_ended' => $web, 'ip' => $ip]);

        return ['cards_frozen' => $frozen, 'cards_failed' => $failed, 'sessions_revoked' => $revoked + $web, 'web_sessions_ended' => $web, 'pin_reset_sent' => $pinReset];
    }

    /**
     * Sign the account out of every browser as well. The stranger the
     * owner is locking out may well be on the website, and revoking the
     * app's tokens alone left that session - and any "remember me" cookie -
     * working after "Secure account".
     */
    private function endWebSessions(User $user): int
    {
        $ended = 0;

        if (config('session.driver') === 'database') {
            try {
                $ended = DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            } catch (Throwable $exception) {
                Log::warning('Lockdown could not end web sessions', ['user_id' => $user->id, 'reason' => $exception->getMessage()]);
            }
        }

        LoginActivity::query()
            ->where('user_id', $user->id)
            ->whereNotNull('session_id')
            ->whereNull('session_revoked_at')
            ->update(['session_revoked_at' => now()]);

        // A remembered browser signs itself back in from this token.
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

        return $ended;
    }
}
