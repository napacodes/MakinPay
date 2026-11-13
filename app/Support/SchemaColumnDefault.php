<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Read a column default back off the live schema.
 *
 * A migration that widens a money column has to restate the default, because
 * `->change()` regenerates the whole definition and drops whatever is left
 * out. Reading it from `Schema::getColumns()` is the only way to avoid
 * hardcoding one — but the two servers this application runs on do not report
 * it the same way.
 *
 * MySQL reports a `DEFAULT NULL` column as a real null. MariaDB reports the
 * four-character string "NULL", and quotes literal defaults besides, so 0
 * arrives as "'0'". Passing either straight back to `->default()` produces a
 * column MariaDB rejects —
 *
 *     alter table `currency_roles` modify `min_limit` decimal(24,8) null default 'NULL'
 *     SQLSTATE[42000]: 1067 Invalid default value for 'min_limit'
 *
 * — which failed the update outright. Casting to float instead is worse: it is
 * silent, and it writes DEFAULT 0 onto a rate or limit column that was meant to
 * have no default at all, which is the landmine a zero rate always is.
 */
final class SchemaColumnDefault
{
    /**
     * The default to re-apply, or null when the column has none.
     */
    public static function normalize(mixed $default): float|string|null
    {
        if ($default === null) {
            return null;
        }

        $value = trim((string) $default);

        if ($value === '' || strcasecmp($value, 'NULL') === 0) {
            return null;
        }

        $value = trim($value, "'");

        if ($value === '' || strcasecmp($value, 'NULL') === 0) {
            return null;
        }

        return is_numeric($value) ? (float) $value : $value;
    }
}
