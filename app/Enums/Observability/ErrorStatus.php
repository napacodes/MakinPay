<?php

namespace App\Enums\Observability;

/**
 * Triage state of one error group.
 *
 * Regressed exists because "new" would be a lie: a group that was resolved and
 * then fired again is a different, worse fact than a bug nobody has seen yet,
 * and it is the one an on-call admin needs surfaced first.
 */
enum ErrorStatus: string
{
    case New        = 'new';
    case Triaged    = 'triaged';
    case InProgress = 'in-progress';
    case Resolved   = 'resolved';
    case Ignored    = 'ignored';
    case Regressed  = 'regressed';

    public function label(): string
    {
        return match ($this) {
            self::New        => __('New'),
            self::Triaged    => __('Triaged'),
            self::InProgress => __('In progress'),
            self::Resolved   => __('Resolved'),
            self::Ignored    => __('Ignored'),
            self::Regressed  => __('Regressed'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New        => 'primary',
            self::Triaged    => 'info',
            self::InProgress => 'warning',
            self::Resolved   => 'success',
            self::Ignored    => 'secondary',
            self::Regressed  => 'danger',
        };
    }

    /**
     * Whether this group still counts against the unresolved figure the
     * sidebar badge and the Issues header show.
     */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Resolved, self::Ignored], true);
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return array_values(array_map(
            static fn (self $case): string => $case->value,
            array_filter(self::cases(), static fn (self $case): bool => $case->isOpen())
        ));
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_combine(
            self::all(),
            array_map(static fn (self $case): string => $case->label(), self::cases())
        );
    }
}
