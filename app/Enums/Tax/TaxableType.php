<?php

declare(strict_types=1);

namespace App\Enums\Tax;

/**
 * The transaction types a tax rule can apply to. The value is the stable code
 * stored in `tax_rules.transaction_type`; it never changes once shipped.
 *
 * Mass payments, card top-ups and the other flows are not taxable yet: a rule
 * can only be written for one of these, so nothing outside them is ever
 * taxed by accident.
 */
enum TaxableType: string
{
    case SendMoney   = 'send_money';
    case Remittance  = 'remittance';
    case Withdraw    = 'withdraw';
    case Exchange    = 'exchange';
    case Deposit     = 'deposit';
    case BillPayment = 'bill_payment';

    public function label(): string
    {
        return match ($this) {
            self::SendMoney   => __('Send money'),
            self::Remittance  => __('Remittance'),
            self::Withdraw    => __('Withdraw'),
            self::Exchange    => __('Exchange'),
            self::Deposit     => __('Deposit'),
            self::BillPayment => __('Bill payment'),
        };
    }
}
