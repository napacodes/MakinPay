<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AmountFlow;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * List the card top-ups that moved money at a provider and never moved a
 * wallet.
 *
 * Before the top-up path was reworked, a successful funding wrote a PENDING
 * ledger row signed as a credit and debited nothing. Real provider money sits
 * behind every one of those rows, and what should happen to each is a
 * question for an administrator and the provider — the customer may have
 * spent the balance, the card may have been closed, the funding may have been
 * reversed at the gateway months ago.
 *
 * So this command reports and stops. It issues no UPDATE, no INSERT and no
 * DELETE, and it is deliberately not scheduled: nothing running unattended
 * should be able to touch these rows.
 */
class ReportStrandedVirtualCardTopups extends Command
{
    protected $signature = 'virtual-card:report-stranded-topups
                            {--limit=500 : Maximum rows to list.}';

    protected $description = 'List historical CARD_TOPUP ledger rows that were never debited from a wallet (read-only)';

    public function handle(): int
    {
        $rows = Transaction::query()
            ->where('trx_type', TrxType::CARD_TOPUP->value)
            ->where('status', TrxStatus::PENDING->value)
            ->where('amount_flow', AmountFlow::PLUS->value)
            // The reworked path always writes an attempt record and always
            // signs the row MINUS, so anything still shaped like this predates
            // the fix.
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('virtual_card_topups')
                    ->whereColumn('virtual_card_topups.transaction_id', 'transactions.id');
            })
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get(['id', 'trx_id', 'user_id', 'wallet_reference', 'amount', 'fee', 'payable_amount', 'payable_currency', 'provider', 'created_at']);

        if ($rows->isEmpty()) {
            $this->components->info('No stranded virtual card top-ups found.');

            return self::SUCCESS;
        }

        $this->components->warn(sprintf(
            '%d card top-up(s) were funded at a provider without a matching wallet debit.',
            $rows->count()
        ));
        $this->newLine();

        $this->table(
            ['Trx ID', 'User', 'Wallet', 'Amount', 'Provider', 'Created'],
            $rows->map(fn (Transaction $row): array => [
                $row->trx_id,
                $row->user_id,
                $row->wallet_reference,
                Money::format($row->payable_amount ?? $row->amount, $row->payable_currency),
                $row->provider,
                $row->created_at?->toDateTimeString(),
            ])->all()
        );

        $this->newLine();
        $this->components->info('Read-only report. Reconcile each row with the provider before adjusting any balance.');

        return self::SUCCESS;
    }
}
