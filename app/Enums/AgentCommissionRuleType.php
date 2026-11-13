<?php

namespace App\Enums;

enum AgentCommissionRuleType: string
{
    case PERCENTAGE = 'percentage';
    case FIXED      = 'fixed';

    /**
     * Both components together, e.g. "1.5% + 0.50" — the shape every major
     * processor prices with, and the one the either/or pair could not express.
     */
    case COMBINED = 'combined';

    public static function options(): array
    {
        return array_combine(
            self::values(),
            array_map(fn (self $case) => $case->label(), self::cases())
        );
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::PERCENTAGE => __('Percentage'),
            self::COMBINED   => __('Fixed + Percentage'),
            self::FIXED      => __('Fixed'),
        };
    }
}
