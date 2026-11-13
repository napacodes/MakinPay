<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Fee type for virtual card operations: Fixed or Percent
 */
enum FixPctType: string
{
    /**
     * Raw values, exposed so the deprecated App\Constants\FixPctType shim can
     * derive its constants from here and the two definitions cannot drift.
     */
    public const FIXED_VALUE = 'fixed';

    public const PERCENT_VALUE = 'percent';

    case FIXED   = self::FIXED_VALUE;
    case PERCENT = self::PERCENT_VALUE;

    /**
     * Coerce a string, an enum case or null into an enum case.
     *
     * Model attributes reach callers in both forms: `charge_type` is declared
     * `: string` by its accessor while `user_charge_type` is cast to this enum.
     * Comparing the two with `===` is silently always false, so anything that
     * needs to branch on a fee type must go through here or {@see matches()}.
     */
    public static function normalize(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return self::tryFrom(strtolower(trim((string) $value)));
    }

    /**
     * Compare a value of either form against a specific case.
     */
    public static function matches(mixed $value, self $case): bool
    {
        return self::normalize($value) === $case;
    }

    /**
     * Get the display label for the enum value.
     */
    public function label(): string
    {
        return match ($this) {
            self::FIXED   => __('Fixed'),
            self::PERCENT => __('Percent'),
        };
    }

    /**
     * Get the symbol for the enum value (for UI, e.g. %, currency symbol).
     */
    public function symbol(): string
    {
        return match ($this) {
            self::FIXED   => siteCurrency('symbol'),
            self::PERCENT => '%',
        };
    }

    /**
     * Get all types as key => label array for select options.
     */
    public static function options(): array
    {
        return [
            self::FIXED->value   => siteCurrency('symbol'),
            self::PERCENT->value => '%',
        ];
    }

    /**
     * Check if this enum instance is percent
     */
    public function isPercent(): bool
    {
        return $this === self::PERCENT;
    }

    /**
     * Check if this enum instance is fixed
     */
    public function isFixed(): bool
    {
        return $this === self::FIXED;
    }

    /**
     * Check if the given type is percent (static method for backward compatibility)
     */
    public static function isPercentStatic($type): bool
    {
        return $type === self::PERCENT->value;
    }

    /**
     * Check if the given type is fixed (static method for backward compatibility)
     */
    public static function isFixedStatic($type): bool
    {
        return $type === self::FIXED->value;
    }
}
