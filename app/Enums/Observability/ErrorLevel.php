<?php

namespace App\Enums\Observability;

/**
 * PSR-3 / RFC 5424 severity, stored verbatim.
 *
 * Eight levels go into the database and four badges come out of the UI. The
 * collapse happens at render time on purpose: throwing away the difference
 * between emergency and critical at ingest would make it unrecoverable, and an
 * alert rule that wants to page only on emergency needs the distinction the
 * badge deliberately hides.
 */
enum ErrorLevel: string
{
    case Emergency = 'emergency';
    case Alert     = 'alert';
    case Critical  = 'critical';
    case Error     = 'error';
    case Warning   = 'warning';
    case Notice    = 'notice';
    case Info      = 'info';
    case Debug     = 'debug';

    /**
     * The four-value badge the UI renders: fatal, error, warning or info.
     */
    public function badge(): string
    {
        return match ($this) {
            self::Emergency, self::Alert, self::Critical => 'fatal',
            self::Error   => 'error',
            self::Warning => 'warning',
            self::Notice, self::Info, self::Debug => 'info',
        };
    }

    /**
     * Uppercase text carried inside the badge. Severity must survive
     * grayscale and a screen reader, so colour is never the only signal.
     */
    public function badgeLabel(): string
    {
        return match ($this->badge()) {
            'fatal'   => __('FATAL'),
            'error'   => __('ERROR'),
            'warning' => __('WARNING'),
            default   => __('INFO'),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Emergency => __('Emergency'),
            self::Alert     => __('Alert'),
            self::Critical  => __('Critical'),
            self::Error     => __('Error'),
            self::Warning   => __('Warning'),
            self::Notice    => __('Notice'),
            self::Info      => __('Info'),
            self::Debug     => __('Debug'),
        };
    }

    /**
     * Numeric weight, highest severity first, for ordering the Issues list.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Emergency => 0,
            self::Alert     => 1,
            self::Critical  => 2,
            self::Error     => 3,
            self::Warning   => 4,
            self::Notice    => 5,
            self::Info      => 6,
            self::Debug     => 7,
        };
    }

    /**
     * The PSR-3 names that collapse into one badge. Used by the query parser
     * so level:fatal matches all three of its members.
     *
     * @return list<string>
     */
    public static function namesForBadge(string $badge): array
    {
        return array_values(array_map(
            static fn (self $case): string => $case->value,
            array_filter(self::cases(), static fn (self $case): bool => $case->badge() === $badge)
        ));
    }

    /**
     * @return list<string>
     */
    public static function badges(): array
    {
        return ['fatal', 'error', 'warning', 'info'];
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

    /**
     * Never throws. An unknown level from a third-party reporter becomes an
     * error rather than taking down the collector that was trying to record
     * something else going wrong.
     */
    public static function fromName(?string $name): self
    {
        return self::tryFrom(strtolower((string) $name)) ?? self::Error;
    }
}
