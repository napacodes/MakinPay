<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * The phone number an EDITED account stores.
 *
 * Registration and admin create store `users.phone` through formatPhone(), and
 * phone sign-in, recipient lookup and the uniqueness check all read it in that
 * form. The three edit paths - the web profile, the app's PATCH /me and the
 * admin's info form - saved the number exactly as typed, so an edited
 * 01712-345678 matched none of them and slipped past a uniqueness check that
 * compares formatted values. This is the one place those paths read a typed
 * number, so they store what sign-up would have stored for the same keystrokes.
 *
 * THE ACCOUNT'S REGISTERED COUNTRY gives the dial code. The country cannot be
 * changed on any of these forms, and it is the country sign-up formatted with.
 * A number typed with '+' keeps its own code, and the zero-is-part-of-the-number
 * countries keep their zero: both are formatPhone()'s rules, not repeated here.
 *
 * AN UNCHANGED NUMBER IS LEFT ALONE. Every one of these forms posts the stored
 * phone back with the rest of the profile, and a legacy row stored before the
 * fix would otherwise be rewritten - and un-verified - by an edit to the
 * customer's city. Stored rows are phone:normalize's job, run by the operator.
 */
final class AccountPhone
{
    /**
     * What to store for the typed number: null for a cleared field, the stored
     * value when nothing was changed, otherwise the formatted number.
     */
    public static function forAccount(User $account, mixed $typed): ?string
    {
        $trimmed = trim((string) $typed);

        if ($trimmed === '') {
            return null;
        }

        if ($trimmed === trim((string) $account->phone)) {
            return $account->phone;
        }

        $dialCode = CustomerPhone::dialCode($account->country);

        if ($dialCode === null) {
            return str_starts_with($trimmed, '+') ? formatPhone('', $trimmed) : $trimmed;
        }

        return formatPhone($dialCode, $trimmed);
    }

    /**
     * Whether the typed number, as it would be stored, already belongs to
     * another account. An unchanged or cleared number never does: the unique
     * index already holds for the row as it stands.
     */
    public static function isHeldByAnotherAccount(User $account, mixed $typed): bool
    {
        $phone = self::forAccount($account, $typed);

        if ($phone === null || $phone === $account->phone) {
            return false;
        }

        return User::query()
            ->where('phone', $phone)
            ->whereKeyNot($account->getKey())
            ->exists();
    }
}
