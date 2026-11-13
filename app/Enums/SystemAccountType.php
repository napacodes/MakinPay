<?php

declare(strict_types=1);

namespace App\Enums;

enum SystemAccountType: string
{
    case MARKETPLACE_COMMISSION = 'marketplace_commission';

    public function label(): string
    {
        return match ($this) {
            self::MARKETPLACE_COMMISSION => __('Marketplace Commission'),
        };
    }
}
