<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\User;
use App\Support\AccountPhone;
use Illuminate\Validation\Validator;

/**
 * One phone number, one account.
 *
 * `users.phone` carries a unique index, and registration stores the number
 * dial-code prefixed, so uniqueness has to be checked against that FORMATTED
 * value: a raw 01712-345678 never matches a stored +8801712345678, and a check
 * against the raw input therefore passes every time and leaves the index to
 * refuse the insert as a 500.
 *
 * This lived in four request classes - customer, merchant, agent and the API -
 * and the copies had already drifted. Three guarded on the country error as
 * well as the phone; the agent one guarded on the phone alone, so an
 * unresolvable country left it formatting against an empty dial code and
 * adding a second, confusing error underneath the real one. The guard is kept,
 * because when the country is wrong there is nothing to compare against and
 * the country is the error worth showing.
 *
 * WHAT THIS IS NOT: the guarantee. Two registrations racing each other both
 * pass this check and the second is refused by the unique index. The index is
 * what keeps the data correct; this is what turns "correct" into a field error
 * the customer can act on. Recorded as X21, because the losing race currently
 * surfaces as a server error rather than as that field error.
 */
trait EnsuresPhoneIsUnique
{
    protected function ensurePhoneIsNotAlreadyRegistered(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['country', 'phone'])) {
            return;
        }

        $country = getCountryByCode(strtoupper((string) $this->input('country', '')));

        if (! is_array($country)) {
            return;
        }

        $formatted = formatPhone(
            (string) ($country['dial_code'] ?? ''),
            (string) $this->input('phone'),
        );

        if (User::query()->where('phone', $formatted)->exists()) {
            $validator->errors()->add('phone', __('This phone number is already registered.'));
        }
    }

    /**
     * The same check for an account editing its own number: formatted with the
     * account's registered country, and never refused for the number it holds.
     */
    protected function ensurePhoneIsNotHeldByAnotherAccount(Validator $validator, User $account): void
    {
        if ($validator->errors()->has('phone') || ! $this->has('phone')) {
            return;
        }

        if (AccountPhone::isHeldByAnotherAccount($account, $this->input('phone'))) {
            $validator->errors()->add('phone', __('This phone number is already registered.'));
        }
    }
}
