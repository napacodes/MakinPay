<?php

declare(strict_types=1);

namespace App\Enums\MassPayment;

/**
 * A more precise instruction for a refused line than its issue's own
 * sentence, telling the owner what to type instead.
 *
 * The value is the stable code stored in `mass_payment_rows.metadata` as
 * `issue_hint`, so the preview, the API and the report can render it long
 * after the resolver that found it has gone. The issue code itself is
 * unchanged, so a client branching on it sees nothing new.
 */
enum MassPaymentIssueHint: string
{
    /** A bare local mobile number on a site with no home country to read it against. */
    case InternationalFormat = 'international_format';

    public function message(): string
    {
        return match ($this) {
            self::InternationalFormat => __('Use the international format, starting with + and the country code, e.g. +14155550123.'),
        };
    }
}
