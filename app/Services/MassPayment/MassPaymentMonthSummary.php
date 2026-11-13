<?php

declare(strict_types=1);

namespace App\Services\MassPayment;

use App\Enums\MassPayment\MassPaymentKind;
use App\Enums\MassPayment\MassPaymentRowStatus;
use App\Models\MassPaymentRow;
use App\Models\User;
use App\Support\ApiMoney;
use App\Support\DecimalMoney;
use App\Support\Money;

/**
 * What a customer's batches of one kind paid out this calendar month, for the
 * figure at the top of the app's batch list.
 *
 * One entry PER CURRENCY, never a total across them: an XOF and a USD batch
 * are two lines. The amount is what reached the recipients (the rows' net
 * amount), not what the customer was debited, and only rows actually paid
 * this month count - a batch confirmed last month and finished today counts
 * its rows paid today.
 *
 * Summed by the database on the decimal column, then cut to the currency's
 * precision; nothing here is a float.
 */
class MassPaymentMonthSummary
{
    /**
     * @return array{month: string, currencies: list<array{currency: string, decimals: int|null, paid: string, payments: int, batches: int}>}
     */
    public function forUser(User $user, MassPaymentKind $kind): array
    {
        $from = now()->startOfMonth();

        $groups = MassPaymentRow::query()
            ->join('mass_payment_batches', 'mass_payment_batches.id', '=', 'mass_payment_rows.mass_payment_batch_id')
            ->where('mass_payment_batches.user_id', $user->id)
            ->where('mass_payment_batches.kind', $kind->value)
            ->where('mass_payment_rows.status', MassPaymentRowStatus::Paid->value)
            ->where('mass_payment_rows.paid_at', '>=', $from)
            ->groupBy('mass_payment_batches.currency_code')
            ->orderBy('mass_payment_batches.currency_code')
            ->toBase()
            ->selectRaw('mass_payment_batches.currency_code as currency')
            ->selectRaw('sum(mass_payment_rows.net_amount) as paid')
            ->selectRaw('count(*) as payments')
            ->selectRaw('count(distinct mass_payment_rows.mass_payment_batch_id) as batches')
            ->get();

        $currencies = [];

        foreach ($groups as $group) {
            $currency = (string) $group->currency;

            $currencies[] = [
                'currency' => $currency,
                'decimals' => ApiMoney::decimals($currency),
                'paid'     => ApiMoney::amount(DecimalMoney::normalize((string) ($group->paid ?? '0'), Money::decimals($currency)), $currency),
                'payments' => (int) $group->payments,
                'batches'  => (int) $group->batches,
            ];
        }

        return [
            'month'      => $from->format('Y-m'),
            'currencies' => $currencies,
        ];
    }
}
