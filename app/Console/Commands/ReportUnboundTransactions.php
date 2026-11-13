<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TrxStatus;
use App\Models\Transaction;
use App\Services\Payment\GatewayOriginResolver;
use Illuminate\Console\Command;

/**
 * List the pending transactions whose originating gateway cannot be named.
 *
 * Transactions created before the binding existed carry no gateway code, and
 * are traced back through the method that created them. Most resolve. The ones
 * that do not — a method since renamed, deleted, or sharing its display name
 * with another — can no longer be settled by a callback, because an
 * unverifiable origin is not permission to move money.
 *
 * That is the safe answer and the inconvenient one, so this exists to make the
 * backlog visible before the rule starts refusing anything. It reports and
 * stops: no UPDATE, no INSERT, no DELETE. Each row is an administrator's
 * decision, reconciled against the provider.
 */
class ReportUnboundTransactions extends Command
{
    protected $signature = 'payments:report-unbound-transactions
                            {--limit=500 : Maximum rows to list.}';

    protected $description = 'List pending transactions whose originating payment gateway cannot be resolved (read-only)';

    public function handle(GatewayOriginResolver $resolver): int
    {
        $rows = Transaction::query()
            ->where('status', TrxStatus::PENDING->value)
            ->whereNull('gateway_code')
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get(['id', 'trx_id', 'user_id', 'trx_type', 'provider', 'payable_amount', 'payable_currency', 'created_at']);

        $unresolved = $rows->filter(
            fn (Transaction $transaction): bool => $resolver->resolve($transaction) === null
        );

        if ($unresolved->isEmpty()) {
            $this->components->info('Every pending transaction has a resolvable originating gateway.');

            return self::SUCCESS;
        }

        $this->components->warn(sprintf(
            '%d pending transaction(s) cannot be tied to a gateway and will not settle from a callback.',
            $unresolved->count()
        ));
        $this->newLine();

        $this->table(
            ['Trx ID', 'Type', 'User', 'Method name', 'Amount', 'Created'],
            $unresolved->map(fn (Transaction $transaction): array => [
                $transaction->trx_id,
                $transaction->trx_type?->value,
                $transaction->user_id,
                $transaction->provider,
                $transaction->payable_amount.' '.$transaction->payable_currency,
                $transaction->created_at?->toDateTimeString(),
            ])->all()
        );

        $this->newLine();
        $this->components->info('Read-only report. Reconcile each row with the provider before completing it by hand.');

        return self::SUCCESS;
    }
}
