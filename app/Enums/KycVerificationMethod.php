<?php

namespace App\Enums;

enum KycVerificationMethod: string
{
    case Manual = 'manual';
    case Auto   = 'auto';

    public function label(): string
    {
        return match ($this) {
            self::Manual => __('Manual review'),
            self::Auto   => __('Automated'),
        };
    }
}
