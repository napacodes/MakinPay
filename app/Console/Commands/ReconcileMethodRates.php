<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\DecimalMoney;
use App\Support\RateDivergence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Pull payment methods back onto the rate of the currency they settle in.
 *
 * The same pair is priced in two places: `currencies.exchange_rate` is what
 * the platform quotes everything against, and each method carries a
 * `conversion_rate` used to bill the processor. A gap between them is not
 * automatically wrong — a processor prices its own FX and adds a spread — but
 * a large one always is, because no spread is 98%.
 *
 * On this install the gaps came from the seeder: `PaymentGatewayMethodSeeder`
 * shipped a frozen rate table, the operator then set real rates on the
 * currencies, and nothing reconciled the two. Paystack quoted NGN at 18.11
 * against a currency rate of 1375.12 — the same deposit priced 98.68% apart
 * depending on which button the customer pressed. The seeder now reads the
 * currency table, so new installs cannot drift this way; this repairs the ones
 * already seeded.
 *
 * Deliberately a command and not a migration, for the same reason
 * `money:fix-method-limits` is: an unattended deploy must not rewrite live
 * payment pricing. It reports first, writes only under --apply, and saves the
 * previous rates to CSV before touching anything.
 *
 * Methods on live rates are skipped — `currency:refresh-rates` owns those, and
 * overwriting one here would only be undone at the next tick.
 */
class ReconcileMethodRates extends Command
{
    protected $signature = 'money:reconcile-method-rates
                            {--tolerance= : Percent a method may sit away from its currency before it is reported. Defaults to the rate_divergence_tolerance_percent setting.}
                            {--apply : Write the currency rate onto the divergent methods. Without this the command only reports.}
                            {--force : Skip the confirmation prompt, for scripted runs.}';

    protected $description = 'Align deposit and withdraw method conversion rates with the currency they settle in';

    public function handle(RateDivergence $divergence): int
    {
        $tolerance  = $this->option('tolerance') === null ? null : (float) $this->option('tolerance');
        $candidates = $divergence->beyondTolerance($tolerance)
            ->reject(fn (array $row): bool => $this->usesLiveRate($row))
            ->values();

        if ($candidates->isEmpty()) {
            $this->components->info('Every payment method is priced within tolerance of its currency.');

            return self::SUCCESS;
        }

        $this->table(
            ['Method', 'Currency', 'Method rate', 'Currency rate', 'Apart'],
            $candidates->map(fn (array $row): array => [
                $row['kind'].': '.$row['name'],
                $row['currency'],
                $row['method_rate'],
                $row['currency_rate'],
                DecimalMoney::round($row['percent'], 2).'%',
            ])->all()
        );

        $this->components->info(
            'The currency rate is the authority; each method above would be set to it. '
            .'A method that genuinely prices its own FX should be left alone and its spread kept inside the tolerance.'
        );

        if (! $this->option('apply')) {
            $this->components->warn('Nothing was changed. Re-run with --apply to write these rates.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(sprintf('Overwrite the rate on %d payment method(s)?', $candidates->count()))) {
            $this->components->info('Aborted. Nothing was changed.');

            return self::SUCCESS;
        }

        $this->writeCsv($candidates->all());

        $written = 0;

        DB::transaction(function () use ($candidates, &$written): void {
            foreach ($candidates as $row) {
                $written += DB::table($row['table'])
                    ->where('id', $row['id'])
                    ->update(['conversion_rate' => $row['currency_rate']]);
            }
        });

        $this->components->info($written.' payment method rate(s) reconciled.');

        return self::SUCCESS;
    }

    /**
     * A method refreshed from a feed is not stale, it is simply between ticks.
     * Writing the currency rate over it would be reverted by the next run of
     * `currency:refresh-rates`, so the divergence has to be resolved there.
     */
    private function usesLiveRate(array $row): bool
    {
        return (bool) DB::table($row['table'])->where('id', $row['id'])->value('conversion_rate_live');
    }

    /**
     * @param list<array<string, mixed>> $candidates
     */
    private function writeCsv(array $candidates): void
    {
        $path = 'money-audit/method-rates-'.now()->format('Ymd-His').'.csv';

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['table', 'id', 'name', 'currency', 'previous_rate', 'new_rate', 'percent_apart']);

        foreach ($candidates as $row) {
            fputcsv($handle, [
                $row['table'],
                $row['id'],
                $row['name'],
                $row['currency'],
                $row['method_rate'],
                $row['currency_rate'],
                $row['percent'],
            ]);
        }

        rewind($handle);
        Storage::disk('local')->put($path, stream_get_contents($handle));
        fclose($handle);

        $this->components->info('Previous rates written to storage/app/'.$path);
    }
}
