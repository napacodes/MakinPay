<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PaymentGateway;
use App\Models\Transaction;
use App\Services\Payment\GatewayOriginResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Write down the originating gateway for transactions created before the
 * binding existed.
 *
 * `gateway_code` is what a callback is checked against before it is allowed to
 * settle a transaction. Rows predating the column carry nothing, so they are
 * traced at runtime through the method that created them — and a method since
 * renamed, deleted, or sharing its display name with another leaves the origin
 * unknowable and the transaction permanently unsettleable.
 *
 * This never guesses. A row is only stamped when its origin is provable from
 * one of two machine-written sources:
 *
 *   1. GatewayOriginResolver already names exactly one gateway. That is the
 *      same rule the runtime trusts to authorise settlement, so recording it
 *      changes no security decision — it only freezes today's correct answer
 *      before an administrator renames the method out from under it.
 *   2. `trx_data` carries keys the gateway integration itself wrote, prefixed
 *      with a registered gateway code (`bitnob_reference`, `paymob_intention`,
 *      `stripe_withdraw`, ...). Unlike `provider`, no administrator can edit
 *      these.
 *
 * Anything ambiguous — no evidence, or evidence for more than one gateway — is
 * left NULL and reported. Those stay an administrator's decision, reconciled
 * against the provider, exactly as `payments:report-unbound-transactions` says.
 */
class BackfillTransactionGatewayCode extends Command
{
    protected $signature = 'payments:backfill-gateway-code
                            {--dry-run : Report what would change without writing.}
                            {--chunk=200 : Rows to examine per batch.}';

    protected $description = 'Stamp the originating gateway on transactions that predate the gateway binding';

    public function handle(GatewayOriginResolver $resolver): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $codes  = $this->gatewayCodes();

        if ($codes === []) {
            $this->components->warn('No payment gateways are registered; nothing can be proven. Skipping.');

            return self::SUCCESS;
        }

        $stamped    = 0;
        $unprovable = [];

        Transaction::query()
            ->whereNull('gateway_code')
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($transactions) use ($resolver, $codes, $dryRun, &$stamped, &$unprovable): void {
                foreach ($transactions as $transaction) {
                    $code = $resolver->resolve($transaction) ?? $this->codeFromTrxData($transaction, $codes);

                    if ($code === null) {
                        $unprovable[] = $transaction;

                        continue;
                    }

                    $stamped++;

                    if ($dryRun) {
                        continue;
                    }

                    DB::transaction(function () use ($transaction, $code): void {
                        $locked = Transaction::query()->whereKey($transaction->getKey())->lockForUpdate()->first();

                        if (! $locked || filled($locked->gateway_code)) {
                            return;
                        }

                        $locked->forceFill(['gateway_code' => $code])->save();
                    });
                }
            });

        $this->report($stamped, $unprovable, $dryRun);

        return self::SUCCESS;
    }

    /**
     * The only gateway identity an administrator cannot edit.
     *
     * @return list<string>
     */
    private function gatewayCodes(): array
    {
        return PaymentGateway::query()
            ->whereNotNull('code')
            ->pluck('code')
            ->map(fn ($code): string => strtolower(trim((string) $code)))
            ->filter(fn (string $code): bool => $code !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The gateway whose integration wrote into `trx_data`, when exactly one did.
     *
     * @param list<string> $codes
     */
    private function codeFromTrxData(Transaction $transaction, array $codes): ?string
    {
        $trxData = $transaction->trx_data;

        if (! is_array($trxData) || $trxData === []) {
            return null;
        }

        $keys  = array_map(fn ($key): string => strtolower((string) $key), array_keys($trxData));
        $found = [];

        foreach ($codes as $code) {
            foreach ($keys as $key) {
                if (str_starts_with($key, $code.'_')) {
                    $found[$code] = true;

                    break;
                }
            }
        }

        return count($found) === 1 ? (string) array_key_first($found) : null;
    }

    /**
     * @param list<Transaction> $unprovable
     */
    private function report(int $stamped, array $unprovable, bool $dryRun): void
    {
        $verb = $dryRun ? 'would be stamped' : 'stamped';

        $this->components->info(sprintf('%d transaction(s) %s with a provable originating gateway.', $stamped, $verb));

        if ($unprovable === []) {
            return;
        }

        $this->components->warn(sprintf(
            '%d transaction(s) carry no provable origin and were left unbound.',
            count($unprovable)
        ));

        $this->table(
            ['Trx ID', 'Type', 'Status', 'Method name', 'Amount', 'Created'],
            array_map(fn (Transaction $transaction): array => [
                $transaction->trx_id,
                $transaction->trx_type?->value,
                $transaction->status?->value,
                $transaction->provider,
                $transaction->payable_amount.' '.$transaction->payable_currency,
                $transaction->created_at?->toDateTimeString(),
            ], array_slice($unprovable, 0, 25))
        );

        $this->components->info('Reconcile these with the provider by hand — see payments:report-unbound-transactions.');
    }
}
