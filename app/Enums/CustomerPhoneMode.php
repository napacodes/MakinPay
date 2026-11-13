<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a checkout asks the payer for their phone number.
 *
 * Decided per gateway by App\Support\CustomerPhone, and sent to every
 * checkout (web deposit, hosted merchant checkout, payment link, the app) so
 * each of them shows the same field for the same method.
 */
enum CustomerPhoneMode: string
{
    /** The gateway never reads a phone; asking would only add friction. */
    case None = 'none';

    /** The gateway passes a phone on when it has one. */
    case Optional = 'optional';

    /** The gateway cannot open a payment without the payer's number. */
    case Required = 'required';

    public function isCollected(): bool
    {
        return $this !== self::None;
    }

    public function isRequired(): bool
    {
        return $this === self::Required;
    }
}
