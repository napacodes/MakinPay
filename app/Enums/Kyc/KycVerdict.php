<?php

namespace App\Enums\Kyc;

enum KycVerdict: string
{
    case Clear    = 'clear';
    case Rejected = 'rejected';
    case Review   = 'review';
    case Pending  = 'pending';

    /**
     * Whether this verdict is a final decision (no further provider callback expected).
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Clear, self::Rejected], true);
    }
}
