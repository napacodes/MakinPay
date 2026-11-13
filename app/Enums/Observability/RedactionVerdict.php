<?php

namespace App\Enums\Observability;

/**
 * What the redaction pipeline decided about one field.
 *
 * Unclassified is the fourth, deliberately awkward verdict. A field the rules
 * cannot place is treated as hostile at capture time and dropped, and its path
 * is recorded so a strict-mode export can refuse rather than hand a reader a
 * bundle whose contents nobody can vouch for.
 */
enum RedactionVerdict: string
{
    case Dropped      = 'dropped';
    case Masked       = 'masked';
    case Kept         = 'kept';
    case Unclassified = 'unclassified';

    public function label(): string
    {
        return match ($this) {
            self::Dropped      => __('DROPPED'),
            self::Masked       => __('MASKED'),
            self::Kept         => __('KEPT'),
            self::Unclassified => __('UNCLASSIFIED'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Dropped      => __('Removed entirely. Nothing is written and nothing can be reversed.'),
            self::Masked       => __('Shape kept, value hidden, so the record is still debuggable.'),
            self::Kept         => __('Internal identifier, not personal data.'),
            self::Unclassified => __('No rule matched. Dropped at capture, and blocks a strict export.'),
        };
    }

    /**
     * Token substituted into the stored payload. Kept literal and identical
     * everywhere so any reader can tell a redaction apart
     * from a value that genuinely was that string.
     */
    public function placeholder(): string
    {
        return match ($this) {
            self::Dropped      => '[DROPPED]',
            self::Masked       => '[MASKED]',
            self::Unclassified => '[UNCLASSIFIED]',
            self::Kept         => '',
        };
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
