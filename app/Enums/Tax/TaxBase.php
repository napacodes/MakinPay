<?php

declare(strict_types=1);

namespace App\Enums\Tax;

/**
 * What a rule's percent is taken of: the amount the customer sends, pays or
 * receives (before the fee), or the fee the platform charges on it. A rule's
 * fixed part does not depend on the base.
 */
enum TaxBase: string
{
    case Amount = 'amount';
    case Fee    = 'fee';

    public function label(): string
    {
        return match ($this) {
            self::Amount => __('Amount'),
            self::Fee    => __('Fee'),
        };
    }
}
