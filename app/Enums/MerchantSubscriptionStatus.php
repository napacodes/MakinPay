<?php

namespace App\Enums;

enum MerchantSubscriptionStatus: string
{
    case Pending   = 'pending';
    case Active    = 'active';
    case PastDue   = 'past_due';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending   => __('Pending'),
            self::Active    => __('Active'),
            self::PastDue   => __('Past Due'),
            self::Cancelled => __('Cancelled'),
        };
    }
}
