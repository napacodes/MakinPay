<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an installed add-on stands. An add-on that was never installed has no
 * row at all, so "not installed" is the absence of a status, not a case here.
 */
enum AddonStatus: string
{
    case INSTALLED = 'installed';
    case ACTIVE    = 'active';
    case DISABLED  = 'disabled';
    case FAILED    = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::INSTALLED => __('Installed'),
            self::ACTIVE    => __('Active'),
            self::DISABLED  => __('Disabled'),
            self::FAILED    => __('Install failed'),
        };
    }

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }
}
