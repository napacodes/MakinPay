<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BillPaymentProvider;
use App\Services\BillPayment\Sync\BillerSynchronizer;
use Illuminate\Console\Command;

/**
 * Import or refresh one bill payment provider's biller catalogue, country by
 * country.
 *
 * Never scheduled: a sync is an operator's decision. New billers arrive
 * switched off, nothing is ever deleted, and what an admin edited is kept;
 * see BillerSynchronizer. Each country's report is kept on the provider and
 * shown on its screen.
 */
class SyncBillPaymentBillers extends Command
{
    protected $signature = 'bill-payment:sync-billers
                            {provider : The bill payment provider code}
                            {--country=* : Two-letter country codes to sync (default: the provider\'s sync countries)}
                            {--category=* : Bill category codes to sync, such as electricity or tv (default: every mapped category)}
                            {--dry-run : Count what would change without writing any biller}';

    protected $description = 'Sync a bill payment provider\'s biller catalogue without deleting billers or overriding admin edits';

    public function handle(BillerSynchronizer $synchronizer): int
    {
        $code     = strtolower(trim((string) $this->argument('provider')));
        $provider = BillPaymentProvider::query()->where('code', $code)->first();

        if ($provider === null) {
            $this->error(sprintf('No bill payment provider has the code "%s".', $code));

            return self::FAILURE;
        }

        $countries = self::codes((array) $this->option('country'), 'strtoupper');

        if ($countries === []) {
            $countries = self::codes((array) ($provider->config['sync_countries'] ?? []), 'strtoupper');
        }

        if ($countries === []) {
            $this->error('Name a country with --country, or set the provider\'s sync countries.');

            return self::FAILURE;
        }

        $categories = self::codes((array) $this->option('category'), 'strtolower');
        $dryRun     = (bool) $this->option('dry-run');
        $failed     = false;

        foreach ($countries as $country) {
            $report = $synchronizer->sync($provider, $country, $categories, $dryRun)->toArray();

            if ($report['error'] !== null) {
                $failed = true;
                $this->error(sprintf('%s: %s', $country, $report['error']));

                continue;
            }

            $this->info(sprintf(
                '%s%s: %d created, %d updated, %d unchanged, %d disabled as missing; packages %d created, %d updated, %d disabled; %d skipped, %d conflicts.',
                $country,
                $dryRun ? ' (dry run)' : '',
                $report['created'],
                $report['updated'],
                $report['unchanged'],
                $report['disabled_missing'],
                $report['items_created'],
                $report['items_updated'],
                $report['items_disabled'],
                array_sum($report['skipped']),
                count($report['conflicts']),
            ));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Trimmed, cased, de-duplicated codes; comma-separated values are split.
     *
     * @param  list<mixed>               $values
     * @param  'strtolower'|'strtoupper' $case
     * @return list<string>
     */
    private static function codes(array $values, string $case): array
    {
        $codes = [];

        foreach ($values as $value) {
            foreach (preg_split('/[\s,;]+/', (string) $value) ?: [] as $code) {
                $code = $case(trim($code));

                if ($code !== '') {
                    $codes[$code] = true;
                }
            }
        }

        return array_map('strval', array_keys($codes));
    }
}
