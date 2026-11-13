<?php

namespace App\Enums\Observability;

/**
 * Where an error came in from.
 *
 * Fourteen sources, one ingest pipe. The value doubles as the machine label
 * the query syntax accepts, so source:gateway in the search box and the column
 * in the database are the same string and can never drift apart.
 */
enum ErrorSource: string
{
    case Php        = 'php';
    case Http       = 'http';
    case Database   = 'database';
    case Queue      = 'queue';
    case Cron       = 'cron';
    case Gateway    = 'gateway';
    case Webhook    = 'webhook';
    case Validation = 'validation';
    case Auth       = 'auth';
    case Frontend   = 'frontend';
    case Mobile     = 'mobile';
    case Infra      = 'infra';
    case Security   = 'security';
    case Manual     = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Php        => __('PHP exceptions'),
            self::Http       => __('HTTP 4xx / 5xx'),
            self::Database   => __('Database'),
            self::Queue      => __('Queue & jobs'),
            self::Cron       => __('Scheduled tasks'),
            self::Gateway    => __('Third-party API'),
            self::Webhook    => __('Webhooks'),
            self::Validation => __('Validation & rules'),
            self::Auth       => __('Auth & authorization'),
            self::Frontend   => __('Frontend JavaScript'),
            self::Mobile     => __('Mobile / API client'),
            self::Infra      => __('Infrastructure'),
            self::Security   => __('Security events'),
            self::Manual     => __('Manual / custom'),
        };
    }

    /**
     * Short chip text for the Issues table, where the column is narrow and
     * the full label would truncate in every language but English.
     */
    public function chipLabel(): string
    {
        return match ($this) {
            self::Php        => __('PHP'),
            self::Http       => __('HTTP'),
            self::Database   => __('Database'),
            self::Queue      => __('Queue'),
            self::Cron       => __('Cron'),
            self::Gateway    => __('Gateway'),
            self::Webhook    => __('Webhook'),
            self::Validation => __('Validation'),
            self::Auth       => __('Auth'),
            self::Frontend   => __('Frontend'),
            self::Mobile     => __('Mobile'),
            self::Infra      => __('Infra'),
            self::Security   => __('Security'),
            self::Manual     => __('Manual'),
        };
    }

    /**
     * The level assigned when the reporting site does not state one.
     *
     * A failed webhook and a crossed infrastructure threshold default to
     * fatal because both mean money has already stopped moving, while a
     * validation failure is a user typing badly and must not page anyone.
     */
    public function defaultLevel(): ErrorLevel
    {
        return match ($this) {
            self::Webhook, self::Infra => ErrorLevel::Critical,
            self::Validation, self::Auth, self::Security => ErrorLevel::Warning,
            self::Frontend, self::Manual => ErrorLevel::Info,
            default => ErrorLevel::Error,
        };
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

    public static function fromName(?string $name): self
    {
        return self::tryFrom(strtolower((string) $name)) ?? self::Php;
    }
}
