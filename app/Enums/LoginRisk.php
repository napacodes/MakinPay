<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the risk evaluator concluded about one sign-in.
 *
 * The order of the cases is the order of severity, which `atLeast()` and the
 * activity screen's "Flagged" filter both read: everything above NORMAL is
 * something an operator is being asked to look at.
 */
enum LoginRisk: string
{
    case NORMAL = 'normal';

    case NEW_DEVICE = 'new_device';

    case SUSPICIOUS = 'suspicious';

    case BLOCKED = 'blocked';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_combine(self::all(), array_map(fn (self $case): string => $case->label(), self::cases()));
    }

    /**
     * The cases the screen calls "flagged" — everything that is not routine.
     *
     * @return list<string>
     */
    public static function flagged(): array
    {
        return array_values(array_diff(self::all(), [self::NORMAL->value]));
    }

    public function label(): string
    {
        return match ($this) {
            self::NORMAL     => __('Normal'),
            self::NEW_DEVICE => __('New device'),
            self::SUSPICIOUS => __('Suspicious'),
            self::BLOCKED    => __('Blocked'),
        };
    }

    /**
     * A page-header / pill tone, not a Bootstrap colour.
     */
    public function tone(): string
    {
        return match ($this) {
            self::NORMAL     => 'neutral',
            self::NEW_DEVICE => 'brand',
            self::SUSPICIOUS => 'warning',
            self::BLOCKED    => 'danger',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NORMAL     => 'secondary',
            self::NEW_DEVICE => 'primary',
            self::SUSPICIOUS => 'warning',
            self::BLOCKED    => 'danger',
        };
    }

    public function isFlagged(): bool
    {
        return $this !== self::NORMAL;
    }
}
