<?php

declare(strict_types=1);

namespace App\Enums\Tax;

use App\Models\User;

/**
 * Which customers a tax rule applies to, always judged by the customer's own
 * country (set at registration and not self-editable) against the site's home
 * country, in every flow.
 *
 *   All            every customer.
 *   Local          customers whose country is the home country.
 *   International  customers whose country is a known country other than the
 *                  home country.
 *   Countries      customers whose country is one of the rule's countries.
 *
 * With no home country set, neither Local nor International matches anyone,
 * as {@see User::isLocalUser()} and {@see User::isInternationalUser()} read it.
 */
enum TaxScope: string
{
    case All           = 'all';
    case Local         = 'local';
    case International = 'international';
    case Countries     = 'countries';

    public function label(): string
    {
        return match ($this) {
            self::All           => __('Everyone'),
            self::Local         => __('Local customers'),
            self::International => __('International customers'),
            self::Countries     => __('Customers from selected countries'),
        };
    }

    /**
     * Whether this scope covers the customer.
     *
     * @param list<string> $countries upper-case ISO codes, read for Countries only
     */
    public function covers(User $user, array $countries): bool
    {
        return match ($this) {
            self::All           => true,
            self::Local         => $user->isLocalUser(),
            self::International => $user->isInternationalUser(),
            self::Countries     => in_array(strtoupper(trim((string) $user->country)), $countries, true),
        };
    }
}
