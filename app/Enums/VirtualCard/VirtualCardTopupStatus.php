<?php

declare(strict_types=1);

namespace App\Enums\VirtualCard;

/**
 * Where a top-up attempt ended up.
 *
 * Terminal outcomes only. How it got there — and whether the provider was
 * ever reached — is {@see VirtualCardTopupDispatchState}.
 */
enum VirtualCardTopupStatus: string
{
    case Pending   = 'pending';
    case Completed = 'completed';
    case Failed    = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending   => __('Pending'),
            self::Completed => __('Completed'),
            self::Failed    => __('Failed'),
        };
    }
}
