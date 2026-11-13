<?php

namespace App\Support;

/**
 * Which permissions only let somebody look.
 *
 * The permission editor offers a "View only" quick set per module, and that
 * needs one answer to "is this permission read-only" that the server and the
 * browser both agree on. The rule is the permission's own name: the catalogue
 * is named consistently enough that a suffix says what a capability does.
 *
 * This lives here rather than on a `Permission` model because the application
 * uses Spatie's model directly. Swapping that for a subclass would put a new
 * class in the path of every permission lookup and cache in a financial
 * admin — a large risk to take for somewhere to hang one predicate.
 */
final class PermissionScope
{
    /**
     * Capabilities that only read.
     *
     * @var list<string>
     */
    private const READ_ONLY_SUFFIXES = [
        '-list', '-view', '-stats', '-chart', '-balance',
        '-growth', '-transactions', '-users', '-health', '-info',
    ];

    /**
     * Named exceptions — dashboard widgets that only read, but whose names do
     * not end in one of the suffixes above.
     *
     * @var list<string>
     */
    private const READ_ONLY_PREFIXES = ['wallet-latest', 'dashboard-queue', 'system-health'];

    public static function isReadOnly(string $name): bool
    {
        foreach (self::READ_ONLY_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        foreach (self::READ_ONLY_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  iterable<string> $names
     * @return list<string>
     */
    public static function readOnly(iterable $names): array
    {
        $readOnly = [];

        foreach ($names as $name) {
            if (self::isReadOnly((string) $name)) {
                $readOnly[] = (string) $name;
            }
        }

        return $readOnly;
    }
}
