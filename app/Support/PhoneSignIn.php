<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Signing in by a phone number that could mean more than one account.
 *
 * With a home country set, a bare number can be read two ways (see
 * PhoneIdentifier::candidates()), and the two readings can belong to two
 * different people. The password decides, and only when it decides cleanly:
 * the sign-in goes through when EXACTLY ONE of the accounts accepts it. When
 * none does it is an ordinary wrong password; when both do - two people with
 * the same password - nobody is signed in, because guessing between two
 * wallets is how a customer lands in a stranger's. Both can still sign in
 * with the '+' and country code, which always has a single reading.
 *
 * DO NOT SHORT-CIRCUIT THE LOOP. Checking every candidate, even after one has
 * accepted, is what enforces "exactly one"; stopping at the first match would
 * let the order of the readings choose an account. The cost is one password
 * check per candidate - each failed one padded to the guard's 200 ms timebox
 * - and one more on the web, where the winner is signed in through the
 * ordinary attempt so remember-me, rehashing and the Login event are
 * unchanged.
 *
 * Never hand the guard the candidates as one credential: the user provider
 * would turn an array into whereIn()->first(), with no ORDER BY - exactly the
 * guess this class exists to refuse.
 */
final class PhoneSignIn
{
    /**
     * The customer guard: merchants and agents are User rows on it too.
     */
    public const string GUARD = 'web';

    /**
     * Check the password against every candidate number.
     *
     * @param  list<string>                                                                                           $candidates from PhoneIdentifier::candidates()
     * @return array{user: ?User, phone: ?string, accepted: int, refused: list<array{phone: string, account: ?User}>}
     */
    public static function resolve(array $candidates, #[\SensitiveParameter] string $password): array
    {
        $guard    = Auth::guard(self::GUARD);
        $accepted = [];
        $refused  = [];

        foreach ($candidates as $phone) {
            $valid = $guard->validate([
                'phone'               => $phone,
                'password'            => $password,
                'system_account_type' => null,
            ]);

            $account = $guard->getLastAttempted();
            $account = $account instanceof User ? $account : null;

            if ($valid && $account !== null) {
                $accepted[] = ['phone' => $phone, 'account' => $account];

                continue;
            }

            $refused[] = ['phone' => $phone, 'account' => $account];
        }

        if (count($accepted) > 1) {
            // Account ids only: never the number typed, never the password.
            Log::warning('Phone sign-in refused: one typed number opened more than one account.', [
                'account_ids' => array_map(fn (array $match): int|string => $match['account']->getKey(), $accepted),
            ]);
        }

        $winner = count($accepted) === 1 ? $accepted[0] : null;

        return [
            'user'     => $winner['account'] ?? null,
            'phone'    => $winner['phone']   ?? null,
            'accepted' => count($accepted),
            'refused'  => $refused,
        ];
    }
}
