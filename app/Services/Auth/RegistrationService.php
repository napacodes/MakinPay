<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Events\TransactionUpdated;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserFeature;
use Closure;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Everything the three registration forms were each doing separately.
 *
 * The customer, merchant and agent controllers held the same forty lines three
 * times: country parsing, phone formatting, the referral lookup, two events
 * and the per-user feature sync. Three copies is three chances for one to
 * drift, and the app needs a fourth caller, so they now share this.
 *
 * What stays with the caller is what genuinely differs: each controller still
 * decides where to send the browser, and the agent path still builds its own
 * profile - but now INSIDE the same transaction, through the
 * $withinTransaction callback, because building it afterwards is what left
 * half-made agents behind when a site had no usable currency.
 *
 * The two events fire after the commit rather than mid-registration. That is a
 * change from how this read when it was first extracted, and the reason is in
 * register() itself.
 *
 * The referral code is a parameter rather than read from the cookie in here.
 * The web reads it from a cookie HandleReferralLinks set; a phone has no
 * cookie and sends the code in the request body. A service that reached for
 * the cookie itself would work for one caller and silently do nothing for the
 * other.
 */
final class RegistrationService
{
    /**
     * @param array<string, mixed> $attributes
     *
     * @throws ValidationException When the country code is not one the platform knows.
     */
    public function register(
        UserRole $role,
        array $attributes,
        ?string $referralCode = null,
        ?Closure $withinTransaction = null,
    ): User {
        // Outside the transaction on purpose: an unknown country is a bad
        // request, not a failed write, and there is nothing to roll back yet.
        $country = $this->resolveCountry((string) ($attributes['country'] ?? ''));

        /*
         * ALL OR NOTHING. Creating an account is half a dozen writes, and any
         * one of them failing used to leave the rest behind. The reported case
         * was the agent path: AgentService::createDefaultForUser throws when
         * the site has no active currency, and the customer survived as an
         * AGENT with no agent profile - able to sign in, with nothing there.
         *
         * $withinTransaction is how a caller adds its own role-specific setup
         * to the same transaction rather than doing it afterwards and hoping.
         */
        try {
            $user = DB::transaction(function () use ($role, $attributes, $country, $referralCode, $withinTransaction): User {
                $user = User::create($this->userAttributes($role, $attributes, $country));

                $this->recordReferral($user, $referralCode);

                UserFeature::syncWithConfigForUser($user->id);

                if ($withinTransaction !== null) {
                    $withinTransaction($user);
                }

                return $user;
            });
        } catch (QueryException $e) {
            /*
             * THE RACE THE FORM REQUEST CANNOT CLOSE.
             *
             * EnsuresPhoneIsUnique checks and then this inserts, which is not
             * atomic: two registrations with the same number both pass the
             * check and the unique index on users.phone refuses the second.
             * The index is what keeps the data correct and is not the
             * problem. What was wrong is what the LOSER saw - a
             * QueryException, so a 500 on the web form and a ServerError
             * envelope on the API, for a collision that answers politely when
             * it happens a moment earlier.
             *
             * Translated here rather than in each of the four callers,
             * because the race is a property of the insert and not of any one
             * form.
             */
            if ($this->isDuplicatePhone($e)) {
                throw ValidationException::withMessages([
                    'phone' => __('This phone number is already registered.'),
                ]);
            }

            throw $e;
        }

        /*
         * AFTER THE COMMIT, and this is an ORDERING CHANGE: the events used to
         * fire between the user row and the feature sync. Registered is what
         * sends the verification email, and an email announcing an account
         * that was rolled back a moment later is not something the customer
         * can do anything about.
         *
         * Nothing depends on the old order - UpdateUserRanking reads
         * transactions and ranks, and the mailer reads the user - but the new
         * one is asserted in RegistrationAtomicityTest rather than assumed.
         */
        event(new Registered($user));
        event(new TransactionUpdated($user));

        return $user;
    }

    /**
     * @param  array<string, mixed> $attributes
     * @param  array<string, mixed> $country
     * @return array<string, mixed>
     */
    private function userAttributes(UserRole $role, array $attributes, array $country): array
    {
        $values = [
            'first_name' => $attributes['first_name'],
            'last_name'  => $attributes['last_name'],
            'username'   => $attributes['username'],
            'email'      => $attributes['email'],
            'country'    => $country['code'],
            'phone'      => formatPhone((string) ($country['dial_code'] ?? ''), (string) $attributes['phone']),
            'role'       => $role,
            'password'   => Hash::make((string) $attributes['password']),
        ];

        if ($role === UserRole::MERCHANT) {
            $values['business_name']    = $attributes['business_name'];
            $values['business_address'] = $attributes['business_address'] ?? null;
        }

        return $values;
    }

    /**
     * @return array{code: string, dial_code: ?string}
     *
     * @throws ValidationException
     */
    private function resolveCountry(string $raw): array
    {
        $code    = strtoupper($raw);
        $country = getCountryByCode($code);

        if (! $country) {
            throw ValidationException::withMessages([
                'country' => __('The selected country is invalid.'),
            ]);
        }

        return ['code' => $code, 'dial_code' => $country['dial_code'] ?? null];
    }

    /**
     * Credit whoever sent this customer, and chain the row to the referrer's
     * own referral so the tree stays walkable.
     */
    private function recordReferral(User $user, ?string $referralCode): void
    {
        if ($referralCode === null || $referralCode === '') {
            return;
        }

        $referrer = User::where('referral_code', $referralCode)->first();

        if (! $referrer) {
            return;
        }

        Referral::create([
            'user_id'            => $referrer->id,
            'referred_user_id'   => $user->id,
            'parent_referral_id' => Referral::where('referred_user_id', $referrer->id)->first()?->id,
        ]);
    }

    /**
     * Is this a unique-constraint violation naming the phone column?
     *
     * 23000/23505 is "integrity constraint violation" for EVERY unique index
     * on the table, so the state alone would tell a customer whose username
     * was taken that their phone number is already registered - a message
     * they cannot act on, about a field they did not touch.
     *
     * ONLY THE CONSTRAINT PART OF THE MESSAGE IS READ. Laravel appends the
     * full SQL, and that INSERT names every column - so a plain search for
     * "phone" matches a username collision too. That is not hypothetical: it
     * is what the first version of this did, and the test for the username
     * case is what caught it.
     *
     * Both spellings are matched because the drivers differ: SQLite and
     * Postgres name the column (`users.phone`), MySQL names the index
     * (`users_phone_unique`). Dropping either spelling is caught by a test;
     * they are not interchangeable.
     *
     * Two of the guards here survive mutation and are kept deliberately.
     * Searching the WHOLE message instead of the constraint part still
     * passes, because the qualified `users.phone` does not appear in the
     * appended SQL - which quotes bare column names - and the SQLSTATE check
     * can be removed for the same reason. Both are cheap insurance against a
     * driver that renders either part differently, and the qualified search
     * doing the work today is not a promise that it will keep doing it.
     */
    private function isDuplicatePhone(QueryException $exception): bool
    {
        $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        if (! in_array($state, ['23000', '23505'], true)) {
            return false;
        }

        $message = strtolower($exception->getMessage());

        // Everything from here on is the query Laravel appended.
        $constraint = strstr($message, '(connection:', true) ?: $message;

        return str_contains($constraint, 'users.phone')
            || str_contains($constraint, 'phone_unique');
    }
}
