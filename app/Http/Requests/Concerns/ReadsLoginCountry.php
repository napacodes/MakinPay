<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Support\PhoneIdentifier;

/**
 * The country picked beside a sign-in phone field.
 *
 * The phone-first sign-in forms - web and app, password and SMS code - post
 * `login_country` with the number: the ISO-2 code of the country the customer
 * chose in the picker. A number typed under a country other than home is
 * rewritten into international form BEFORE validation, so the throttle keys,
 * the credential attempt and the SMS code all see the one number the
 * customer meant (PhoneIdentifier::inPickedCountry()).
 *
 * Optional, and additive. A client that never sends the field - an older app
 * build, the email or username tab - posts exactly what it posted before and
 * is read exactly as before. The field is not validated: an unknown or
 * malformed country simply rewrites nothing, which is the old behaviour, so
 * there is no new way for a sign-in to fail.
 *
 * Only send it from the phone field. The rewrite touches nothing but a bare
 * national number, but an all-digit USERNAME is one, and under a foreign
 * country it would be read as a phone instead.
 */
trait ReadsLoginCountry
{
    protected function prepareForValidation(): void
    {
        $login   = $this->input('login');
        $country = $this->input('login_country');

        if (! is_string($login) || ! is_string($country)) {
            return;
        }

        $this->merge(['login' => PhoneIdentifier::inPickedCountry($login, $country)]);
    }
}
