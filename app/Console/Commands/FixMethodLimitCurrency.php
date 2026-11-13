<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\DecimalMoney;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Restate deposit and withdraw limits that were stored in the method's own
 * currency instead of the platform's base currency.
 *
 * `PaymentGatewayMethodSeeder::limitsFor()` used to multiply its limits by the
 * method's conversion rate, so a EUR method seeded from "10 to 5000" landed in
 * the database as 9.20 and 4600. Nothing else in the codebase read the column
 * that way — the admin form, the deposit page and PaymentService all treat it
 * as base currency — so the method silently enforced "between 9.20 and 4600
 * USD" while appearing to offer EUR limits. The seeder is fixed; this repairs
 * databases already seeded by it.
 *
 * Deliberately a command rather than a migration. A migration would run
 * unattended on every customer's deploy and rewrite live payment
 * configuration on the strength of a heuristic. This asks first, shows exactly
 * what it would change, and writes the previous values to a CSV before
 * touching anything.
 *
 * The heuristic is the same one `money:audit-precision` reports: both limits
 * dividing evenly by the same rate. Either alone can be a round number by
 * chance; both together essentially cannot.
 */
class FixMethodLimitCurrency extends Command
{
    protected $signature = 'money:fix-method-limits
                            {--apply : Write the corrected limits. Without this the command only reports.}
                            {--force : Skip the confirmation prompt, for scripted runs.}';

    protected $description = 'Restate deposit/withdraw limits that were stored in the method currency instead of the base currency';

    /**
     * table => [min column, max column, seeded base min, seeded base max]
     *
     * The last two are the figures `PaymentGatewayMethodSeeder` passes to
     * `limitsFor()`, and they are what makes this safe to act on: see
     * {@see wasSeededFromBase()}.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private const TABLES = [
        'deposit_methods'  => ['min_deposit', 'max_deposit', '10', '5000'],
        'withdraw_methods' => ['min_withdraw', 'max_withdraw', '20', '2500'],
    ];

    public function handle(): int
    {
        $candidates = $this->candidates();

        if ($candidates === []) {
            $this->components->info('No method limits look like they were entered in the method currency.');

            return self::SUCCESS;
        }

        $this->table(
            ['Method', 'Currency', 'Rate', 'Stored', 'Corrected'],
            array_map(fn (array $row): array => [
                $row['name'],
                $row['currency'],
                $this->tidy($row['rate']),
                $this->tidy($row['min']).' / '.$this->tidy($row['max']),
                $this->tidy($row['newMin']).' / '.$this->tidy($row['newMax']),
            ], $candidates)
        );

        $this->components->info(sprintf(
            'Limits are denominated in %s. The corrected column is the stored value divided by the method rate.',
            siteCurrency(),
        ));

        if (! $this->option('apply')) {
            $this->components->warn('Nothing was changed. Re-run with --apply to write these values.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Apply these corrections to live payment methods?', false)) {
            $this->components->warn('Aborted. Nothing was changed.');

            return self::SUCCESS;
        }

        $backup = $this->writeBackup($candidates);

        DB::transaction(function () use ($candidates): void {
            foreach ($candidates as $row) {
                DB::table($row['table'])->where('id', $row['id'])->update([
                    $row['minColumn'] => $row['newMin'],
                    $row['maxColumn'] => $row['newMax'],
                ]);
            }
        });

        $this->components->info(sprintf('Updated %d method(s). Previous values saved to %s.', count($candidates), $backup));

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function candidates(): array
    {
        $candidates = [];

        foreach (self::TABLES as $table => [$minColumn, $maxColumn, $seededMin, $seededMax]) {
            $rows = DB::table($table)
                ->select('id', 'name', 'currency', 'conversion_rate', $minColumn, $maxColumn)
                ->where('conversion_rate', '<>', 1)
                ->where('conversion_rate', '>', 0)
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                $rate = $this->decimal($row->conversion_rate);
                $min  = $this->decimal($row->{$minColumn});
                $max  = $this->decimal($row->{$maxColumn});

                if (bccomp($min, '0', 8) <= 0 || bccomp($max, '0', 8) <= 0) {
                    continue;
                }

                $newMin = $this->wholeNumber(bcdiv($min, $rate, 8));
                $newMax = $this->wholeNumber(bcdiv($max, $rate, 8));

                if ($newMin === null || $newMax === null) {
                    continue;
                }

                if (! $this->wasSeededFromBase($newMin, $newMax, $seededMin, $seededMax)) {
                    continue;
                }

                $candidates[] = [
                    'table'     => $table,
                    'id'        => $row->id,
                    'name'      => $row->name,
                    'currency'  => $row->currency,
                    'rate'      => $rate,
                    'minColumn' => $minColumn,
                    'maxColumn' => $maxColumn,
                    'min'       => $min,
                    'max'       => $max,
                    'newMin'    => $newMin,
                    'newMax'    => $newMax,
                ];
            }
        }

        return $candidates;
    }

    /**
     * Read a database value as an exact decimal string.
     *
     * MySQL returns a decimal column as a string already; SQLite returns a
     * native float, and running that through a high-precision normalizer
     * faithfully expands the float64 noise — 0.92 becomes
     * "0.92000000000000004", and dividing by it turns a clean 10 into
     * 9.99999999. Casting the float to string first takes PHP's shortest
     * round-tripping representation instead, which is the value that was
     * actually meant.
     */
    private function decimal(mixed $value): string
    {
        $string = (string) $value;

        // A very small rate can still arrive in exponent form, which bcmath
        // cannot parse at all.
        return stripos($string, 'e') === false ? $string : DecimalMoney::rate($string);
    }

    /**
     * Whether the correction lands back on the limits the seeder started from.
     *
     * "Both limits divide evenly by the rate" is not enough on its own, and on
     * this install it was actively dangerous. Against a BTC rate of 0.000016
     * any round pair divides evenly, so a hand-set and perfectly sensible
     * "500 to 2000 USD" was reported as converted, and applying the correction
     * would have set the minimum deposit to 31,250,000 USD — the method
     * silently unusable, from a command whose whole purpose is repair.
     *
     * The bug being repaired has one shape: `PaymentGatewayMethodSeeder` took
     * its own base limits and multiplied them by the method rate. So a genuine
     * victim divides back to exactly those base limits, and nothing else does.
     * Requiring that turns a suggestive coincidence into a matched signature,
     * and a method the operator has since edited is left alone — which is
     * right, because at that point its intent is no longer inferable.
     */
    private function wasSeededFromBase(string $newMin, string $newMax, string $seededMin, string $seededMax): bool
    {
        return bccomp($newMin, $seededMin, 8) === 0
            && bccomp($newMax, $seededMax, 8) === 0;
    }

    /**
     * The value as a whole number if it is one, otherwise null.
     *
     * A limit an operator typed is a round figure; dividing it back by the
     * rate should land on that figure. The tolerance absorbs last-digit noise
     * from a float-typed column without weakening the test — a limit that was
     * genuinely entered in the base currency, divided by a non-unit rate, does
     * not land within a millionth of an integer by chance.
     */
    private function wholeNumber(string $value): ?string
    {
        if (bccomp($value, '0', 8) <= 0) {
            return null;
        }

        $truncated = bcadd($value, '0', 0);
        $fraction  = bcsub($value, $truncated, 8);

        // Distance to whichever integer is nearer.
        $distance = bccomp($fraction, '0.5', 8) > 0
            ? bcsub('1', $fraction, 8)
            : $fraction;

        if (bccomp($distance, '0.00000100', 8) > 0) {
            return null;
        }

        return bccomp($fraction, '0.5', 8) > 0 ? bcadd($truncated, '1', 0) : $truncated;
    }

    /**
     * @param list<array<string, mixed>> $candidates
     */
    private function writeBackup(array $candidates): string
    {
        $path   = 'money-audit/method-limits-'.now()->format('Ymd-His').'.csv';
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['table', 'id', 'name', 'currency', 'rate', 'column', 'previous', 'corrected']);

        foreach ($candidates as $row) {
            fputcsv($handle, [$row['table'], $row['id'], $row['name'], $row['currency'], $row['rate'], $row['minColumn'], $row['min'], $row['newMin']]);
            fputcsv($handle, [$row['table'], $row['id'], $row['name'], $row['currency'], $row['rate'], $row['maxColumn'], $row['max'], $row['newMax']]);
        }

        rewind($handle);
        Storage::disk('local')->put($path, stream_get_contents($handle));
        fclose($handle);

        return 'storage/app/'.$path;
    }

    private function tidy(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
