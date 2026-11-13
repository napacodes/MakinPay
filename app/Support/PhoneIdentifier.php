<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Reading a typed login as a phone number.
 *
 * ONE DEFINITION, used by the mobile API and by all three web login forms.
 * It began as a private method on the API's AuthController; copying it into
 * the web request would have been the same mistake the two card providers
 * made with formatPhone(), where a second copy meant one of them never got
 * the fix.
 *
 * Two readings live here. international() is the only one there is when the
 * operator has not chosen a home country, and it is unchanged. candidates()
 * adds the home country's local reading when one is chosen - and because a
 * bare number can then mean two different phones, it returns every reading
 * and leaves PhoneSignIn to accept only when exactly one account takes the
 * password. Every reading carries a '+' and a non-zero first digit, so none
 * of them can ever be a malformed legacy row.
 */
final class PhoneIdentifier
{
    /**
     * The longest number E.164 allows, dial code included.
     */
    private const int MAX_DIGITS = 15;

    /**
     * The shortest subscriber number worth reading as a phone, the same
     * floor CustomerPhone::national() applies at checkout.
     */
    private const int MIN_NATIONAL_DIGITS = 6;

    /**
     * The typed string as a stored phone number, or null if it is not one.
     *
     * ONLY AN UNAMBIGUOUS INTERNATIONAL NUMBER. This is the whole reading
     * when no home country is set: a bare local number means nothing without
     * a country, so assuming one would either match the wrong account or
     * match none and look broken. A leading zero is the tell: it is a trunk
     * prefix, so the number is local and this declines to guess.
     *
     * That guard is not hypothetical. Legacy rows can hold a malformed
     * '+01712345678', and without it a customer typing their own local number
     * would land on that stranger's account.
     *
     * Punctuation is dropped because a customer retyping their own number
     * does not reproduce it, and the stored form has none.
     */
    public static function international(?string $login): ?string
    {
        $trimmed = trim((string) $login);
        $digits  = preg_replace('/\D+/', '', $trimmed) ?? '';

        if ($digits === '' || str_starts_with($digits, '0')) {
            return null;
        }

        if (str_starts_with($trimmed, '+')) {
            return '+'.$digits;
        }

        // Without a '+' the string has to be long enough to carry a dial code
        // and a subscriber number before it is worth reading as one. Short
        // numeric strings are usernames.
        return strlen($digits) >= 10 ? '+'.$digits : null;
    }

    /**
     * Every stored phone number the typed string could mean, at most two.
     *
     * WITHOUT A HOME COUNTRY, or for anything typed with a '+', this is
     * international() and nothing else - today's behaviour, byte for byte.
     *
     * WITH ONE, a number typed the way it is dialled at home is also read
     * against that country, through formatPhone(): the same function
     * registration stores with, so the sign-in can only find what sign-up
     * would have stored for those keystrokes. Côte d'Ivoire, Benin, Congo and
     * Gabon keep their leading zero in the international form, so for them
     * the zero-kept reading is offered too; formatPhone() keeps it itself for
     * the first three since PH1, and withStoredBeforeTheFix() adds the forms
     * their rows were stored in before that, so a row stored either way is
     * reachable.
     *
     * A bare '1712345678' under a Bangladesh home is therefore both the local
     * +8801712345678 and today's +1712345678. Order never decides who signs
     * in - PhoneSignIn checks every reading - it only picks the throttle
     * subject, so the reading the customer most likely meant comes first: the
     * international one when the digits start with the home dial code, the
     * local one otherwise.
     *
     * Usually one or two readings. A zero-keeping country adds its pre-PH1
     * forms, so a Beninese number can have up to three; PhoneSignIn checks
     * them all and still refuses when more than one account answers.
     *
     * @return list<string>
     */
    public static function candidates(?string $login, ?string $homeCountry): array
    {
        $trimmed = trim((string) $login);
        $legacy  = self::international($login);
        $dial    = CustomerPhone::dialCode($homeCountry);

        if ($dial === null || str_starts_with($trimmed, '+')) {
            return $legacy === null ? [] : self::withStoredBeforeTheFix([$legacy]);
        }

        $local  = self::homeReadings($trimmed, $dial, $homeCountry);
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        $ordered = $legacy !== null && str_starts_with($digits, $dial)
            ? [$legacy, ...$local]
            : [...$local, $legacy];

        return self::withStoredBeforeTheFix(array_values(array_unique(array_filter($ordered, fn (?string $phone): bool => $phone !== null))));
    }

    /**
     * The typed login read against the country the customer picked beside
     * the phone field, when that country is not home.
     *
     * The sign-in form's phone field carries a country picker. A number typed
     * under the home country goes through unchanged: candidates() already
     * reads it locally, legacy forms included. A number typed under ANOTHER
     * country is the customer saying "this is a French number", so it is put
     * into international form here, through formatPhone() - the function
     * registration stores with - and everything downstream sees an ordinary
     * '+' number. Without this a French customer typing 06 12 34 56 78 would
     * be read as a home-country number and match nobody, or the wrong person.
     *
     * Anything that is not a bare national number is returned untouched: a
     * '+' number already names its country, a leading '00' is an exit code
     * this does not guess at, and letters or an '@' are an email or username.
     * An unknown country, or one with no dial code, changes nothing.
     */
    public static function inPickedCountry(string $login, ?string $country): string
    {
        $trimmed = trim($login);
        $code    = strtoupper(trim((string) $country));

        if ($code === '' || HomeCountry::includes($code) || preg_match('/^[0-9\s().\-]+$/', $trimmed) !== 1) {
            return $login;
        }

        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';
        $dial   = CustomerPhone::dialCode($code);

        if ($dial === null || $digits === '' || str_starts_with($digits, '00')) {
            return $login;
        }

        $international = formatPhone($dial, $digits);

        return self::isPlausible($international, $dial) ? $international : $login;
    }

    /**
     * PH1: the forms a number may have been stored in before its country
     * kept its zero, after every reading already found, so the customer who
     * signed up then can still sign in by phone.
     *
     * Until PH1, registration dropped the zero for Côte d'Ivoire (+225),
     * Benin (+229) and Congo-Brazzaville (+242) as if it were a trunk
     * prefix, so +225 07 01 23 45 67 was stored as +225701234567. Benin's
     * numbers also all gained their leading 01 on 30 November 2024, so a row
     * from before then holds the old eight digits: +229 97 00 00 00 is today
     * +229 01 97 00 00 00, the same line. Stored rows are not rewritten
     * (that changes customer data and is the operator's call); they are
     * reached here instead, and PhoneSignIn still refuses when two accounts
     * answer.
     *
     * @param  list<string> $readings
     * @return list<string>
     */
    private static function withStoredBeforeTheFix(array $readings): array
    {
        $all = $readings;

        foreach ($readings as $phone) {
            if (preg_match('/^\+(225|229|242)0(\d+)$/', $phone, $match) !== 1) {
                continue;
            }

            [, $dial, $rest] = $match;
            $older           = ['+'.$dial.$rest];

            if ($dial === '229' && str_starts_with($rest, '1') && strlen($rest) === 9) {
                $older[] = '+229'.substr($rest, 1);
            }

            foreach ($older as $legacy) {
                if (self::isPlausible($legacy, $dial)) {
                    $all[] = $legacy;
                }
            }
        }

        return array_values(array_unique($all));
    }

    /**
     * The typed string read as a number dialled inside the home country.
     *
     * Only something that looks like a phone: digits and the punctuation
     * people type between them, no '+', no letters, no '@'. A leading '00' is
     * an international call prefix, and which one varies by country, so it is
     * not guessed at here - typing the '+' is the way in for those.
     *
     * @return list<string>
     */
    private static function homeReadings(string $trimmed, string $dial, ?string $homeCountry): array
    {
        if (preg_match('/^[0-9\s().\-]+$/', $trimmed) !== 1) {
            return [];
        }

        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if ($digits === '' || str_starts_with($digits, '00')) {
            return [];
        }

        $readings = [formatPhone($dial, $digits)];

        if (str_starts_with($digits, '0') && CustomerPhone::zeroIsPartOfTheNumber($homeCountry)) {
            $readings[] = '+'.$dial.$digits;
        }

        // PH2: what registration stored before formatPhone() stopped adding a
        // second dial code - the code in front of whatever was typed - so a
        // row stored that way still signs in until phone:normalize fixes it.
        $readings[] = '+'.$dial.(! phoneKeepsTrunkZero($dial) && str_starts_with($digits, '0') ? substr($digits, 1) : $digits);

        return array_values(array_unique(array_filter($readings, fn (string $phone): bool => self::isPlausible($phone, $dial))));
    }

    /**
     * Whether a reading could be a real number: a '+' and a non-zero digit,
     * no longer than E.164 allows, and enough subscriber digits after the
     * dial code that a short username is never taken for a phone.
     */
    private static function isPlausible(string $phone, string $dial): bool
    {
        if (preg_match('/^\+[1-9][0-9]*$/', $phone) !== 1) {
            return false;
        }

        $digits = strlen($phone) - 1;

        return $digits <= self::MAX_DIGITS && $digits - strlen($dial) >= self::MIN_NATIONAL_DIGITS;
    }
}
