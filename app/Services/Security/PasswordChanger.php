<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\User;
use App\Services\Api\DeviceTokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Giving an account a password its owner chose.
 *
 * ONE DEFINITION for the three places that do it: the settings page, the
 * app's change-password endpoint and the app's password reset. They were
 * three copies, and they had already drifted: the reset never cleared
 * must_change_password, so an account an admin had flagged could reset its
 * password from the app and still be refused at sign-in for the same reason -
 * a loop with no exit short of opening the website.
 *
 * Clearing the flag belongs here because of what it means. The admin set a
 * password the customer did not choose; any of these three paths ends with
 * one the customer did choose, which is exactly the condition the flag waits
 * for.
 *
 * App tokens are revoked because a password change is what someone does when
 * they think a credential is out of their hands, and tokens never expire. The
 * web page used to sign out only other browser sessions, which left every
 * phone that already held a token signed in - including a stolen one.
 *
 * What this deliberately does not touch: the browser session, which only the
 * web path has; and push registrations, which revoking a token leaves behind
 * (logged as P2a, because logout-all has the same gap and one fix covers both).
 */
final class PasswordChanger
{
    public function __construct(private readonly DeviceTokenService $tokens) {}

    /**
     * @param  int|string|null $keepTokenId The app token making the request, which
     *                                      survives so the customer is not signed
     *                                      out of the phone in their hand. Null
     *                                      revokes every token.
     * @return int             App tokens revoked.
     */
    public function change(User $user, string $newPassword, int|string|null $keepTokenId = null): int
    {
        return DB::transaction(function () use ($user, $newPassword, $keepTokenId): int {
            $user->password             = Hash::make($newPassword);
            $user->must_change_password = false;
            $user->forceFill(['password_changed_at' => now()]);
            $user->save();

            return $this->tokens->revokeAllExcept($user, $keepTokenId);
        });
    }
}
