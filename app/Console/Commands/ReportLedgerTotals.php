<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Models\Currency;
use App\Services\Reporting\LedgerRestatement;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * State the ledger in a currency of the operator's choosing.
 *
 * The platform's base currency is a presentation choice under IAS 21, not a
 * property of the money — this makes that concrete. It reads each entry's own
 * recorded rate, so a total is a restatement of what actually happened rather
 * than today's rates applied to history.
 */
class ReportLedgerTotals extends Command
{
    protected $signature = 'money:report
                            {--currency= : Present totals in this currency. Defaults to the platform base.}
                            {--type= : Limit to one transaction type, e.g. deposit.}
                            {--from= : Only entries on or after this date.}
                            {--to= : Only entries on or before this date.}
                            {--all-statuses : Include entries that are not completed.}';

    protected $description = 'Total the ledger in any presentation currency, using each entry\'s recorded exchange rate';

    public function handle(LedgerRestatement $restatement): int
    {
        $currency = strtoupper(trim((string) ($this->option('currency') ?: siteCurrency())));

        if (! Currency::query()->whereRaw('UPPER(code) = ?', [$currency])->exists()) {
            $this->components->error(sprintf('No currency named %s.', $currency));

            return self::FAILURE;
        }

        $type = $this->option('type');

        if ($type !== null && TrxType::tryFrom($type) === null) {
            $this->components->error(sprintf('No transaction type named %s.', $type));

            return self::FAILURE;
        }

        $report = $restatement->totals($currency, function ($query) use ($type) {
            if (! $this->option('all-statuses')) {
                /*
                 * What moved through wallets: completed entries, and a debit
                 * that failed or was cancelled after the money had left -
                 * one with a completed REFUND naming it. That REFUND is
                 * completed, so it is counted; leaving its debit out showed
                 * the money coming back without it ever having gone, and
                 * gross volume drifted by every refunded flow. Counting both
                 * states the two movements, which net to nothing. A failure
                 * that never debited has no refund and stays out.
                 */
                $query->where(fn ($moved) => $moved
                    ->where('status', TrxStatus::COMPLETED->value)
                    ->orWhere(fn ($refunded) => $refunded
                        ->whereIn('status', [TrxStatus::FAILED->value, TrxStatus::CANCELED->value])
                        ->whereHas('refunds', fn ($refund) => $refund->where('status', TrxStatus::COMPLETED->value))));
            }

            if ($type !== null) {
                $query->where('trx_type', $type);
            }

            if ($from = $this->option('from')) {
                $query->where('created_at', '>=', Carbon::parse($from)->startOfDay());
            }

            if ($to = $this->option('to')) {
                $query->where('created_at', '<=', Carbon::parse($to)->endOfDay());
            }

            return $query;
        });

        $this->table(
            ['Measure', 'Amount', 'Entries'],
            [
                ['At each entry\'s own rate', Money::format($report->exact, $currency), $report->entries],
                ['Estimated at current rates', Money::format($report->estimated, $currency), ''],
                ['Total', Money::format($report->total(), $currency), ''],
            ]
        );

        if (bccomp($report->estimated, '0', 8) !== 0) {
            $this->components->warn(
                'Part of this total was recorded against a different base currency. No historical rate exists '
                .'for that pair, so the current rate was used — that portion is an estimate, not a restatement.'
            );
        }

        if ($report->untranslatable > 0) {
            $this->components->warn(sprintf(
                '%d entr%s carry no exchange rate and are excluded. Run money:audit-precision to list them.',
                $report->untranslatable,
                $report->untranslatable === 1 ? 'y' : 'ies',
            ));
        }

        if ($report->isFullyExact()) {
            $this->components->info('Every entry was translated at the rate it was recorded with.');
        }

        return self::SUCCESS;
    }
}
