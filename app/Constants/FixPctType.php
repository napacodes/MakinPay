<?php

namespace App\Constants;

use App\Enums\FixPctType as FixPctTypeEnum;

/**
 * @deprecated Use {@see FixPctTypeEnum}.
 *
 * Two classes carry this name with different semantics: this one holds plain
 * string constants, the other is a backed enum. Services comparing
 * `$type === FixPctType::PERCENT` are correct only while they import *this*
 * one — repointing the import at the enum makes every such comparison silently
 * false, because a string is never identical to an enum case, and every fee in
 * the platform would quietly take the wrong branch.
 *
 * The values are derived from the enum so the two definitions cannot drift.
 * This class is kept rather than deleted because customer code and child
 * themes import it. New code should use FixPctTypeEnum::matches(), which
 * accepts either form.
 */
class FixPctType
{
    public const PERCENT = FixPctTypeEnum::PERCENT_VALUE;

    public const FIXED = FixPctTypeEnum::FIXED_VALUE;

    /**
     * @var array<int, string>
     */
    public const TYPE = [
        self::PERCENT,
        self::FIXED,
    ];

    /**
     * @return array<string, string|null>
     */
    public static function getTypeWithSymbol(): array
    {
        return [
            self::PERCENT => '%',
            self::FIXED   => setting('currency_symbol'),
        ];
    }
}
