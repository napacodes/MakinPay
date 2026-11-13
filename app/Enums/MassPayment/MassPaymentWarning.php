<?php

declare(strict_types=1);

namespace App\Enums\MassPayment;

/**
 * Something about a valid line that is probably a mistake but might not be.
 *
 * A warning never blocks a line. The owner must acknowledge the batch's
 * warnings before it can be confirmed. The value is the stable code stored in
 * `mass_payment_rows.warnings`, alongside `duplicate_of_line`.
 */
enum MassPaymentWarning: string
{
    case DuplicateRecipient = 'duplicate_recipient';
    case DuplicateLine      = 'duplicate_line';
    case DuplicateReference = 'duplicate_reference';

    /** Payout batches only: the same external account, by its account hash. */
    case DuplicateAccount = 'duplicate_account';

    /** Payout batches only: the same external account, amount and note. */
    case DuplicateAccountPayment = 'duplicate_account_payment';

    public function label(): string
    {
        return match ($this) {
            self::DuplicateRecipient      => __('Duplicate recipient'),
            self::DuplicateLine           => __('Duplicate line'),
            self::DuplicateReference      => __('Duplicate reference'),
            self::DuplicateAccount        => __('Duplicate account'),
            self::DuplicateAccountPayment => __('Duplicate payment'),
        };
    }

    /** The sentence shown next to the line, naming the line it repeats when known. */
    public function message(?int $duplicateOfLine = null): string
    {
        if ($duplicateOfLine === null) {
            return match ($this) {
                self::DuplicateRecipient      => __('This recipient appears on more than one line.'),
                self::DuplicateLine           => __('This line repeats another line exactly.'),
                self::DuplicateReference      => __('This reference appears on more than one line.'),
                self::DuplicateAccount        => __('This account appears on more than one line.'),
                self::DuplicateAccountPayment => __('This account is paid the same amount with the same note on another line.'),
            };
        }

        return match ($this) {
            self::DuplicateRecipient      => __('This recipient is also on line :line.', ['line' => $duplicateOfLine]),
            self::DuplicateLine           => __('This line repeats line :line exactly.', ['line' => $duplicateOfLine]),
            self::DuplicateReference      => __('This reference is also on line :line.', ['line' => $duplicateOfLine]),
            self::DuplicateAccount        => __('This account is also on line :line.', ['line' => $duplicateOfLine]),
            self::DuplicateAccountPayment => __('This account is paid the same amount with the same note on line :line.', ['line' => $duplicateOfLine]),
        };
    }

    public function color(): string
    {
        return 'warning';
    }
}
