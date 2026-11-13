<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bring stored phone numbers into the format formatPhone() now produces.
 *
 * That includes, since PH2, a number stored with its dial code twice - an
 * app sign-up's +88088001712345678, or +112025550123 - which formatPhone()
 * now reads as already carrying its code. Like every other correction it is
 * reported first and only written with `--apply`.
 *
 * REPORTS BY DEFAULT AND WRITES NOTHING. Rewriting how every customer's phone
 * number is stored is not something to discover the consequences of
 * afterwards, so the command's whole purpose is to let somebody look first:
 * how many rows change, which ones, and - the part that matters - which
 * changes would collide.
 *
 * `users.phone` is UNIQUE. Two rows that differ today only by a trunk zero
 * become the same number once it is stripped, and the second write fails. So
 * collisions are found and reported BEFORE anything is written, and `--apply`
 * refuses to run while any exist unless it is told explicitly to skip them.
 *
 * A row whose number changes KEEPS its verified status. That is the
 * operator's decision and it is worth writing down why it is not obvious: the
 * verification SMS went to the malformed number, so some of those
 * verifications may never have been genuine. Revoking them would be strictly
 * correct and would lock a great many customers out of features they use
 * today, over a defect that was ours.
 */
class NormalizeStoredPhoneNumbers extends Command
{
    protected $signature = 'phone:normalize
        {--apply : Actually write the changes. Without this the command only reports.}
        {--skip-collisions : Apply the changes that do not collide, leaving the ones that do.}
        {--limit=0 : Show at most this many example rows in the report. 0 shows a summary only.}';

    protected $description = 'Report, and optionally apply, the corrected international format for stored phone numbers';

    public function handle(): int
    {
        $changes    = [];
        $collisions = [];
        $unchanged  = 0;

        User::query()
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->select(['id', 'phone', 'country', 'phone_verified_at'])
            ->orderBy('id')
            ->chunk(500, function ($users) use (&$changes, &$collisions, &$unchanged): void {
                foreach ($users as $user) {
                    $corrected = $this->corrected($user);

                    if ($corrected === null || $corrected === $user->phone) {
                        $unchanged++;

                        continue;
                    }

                    $changes[$user->id] = [
                        'from'     => (string) $user->phone,
                        'to'       => $corrected,
                        'country'  => (string) $user->country,
                        'verified' => $user->phone_verified_at !== null,
                    ];
                }
            });

        $collisions = $this->collisions($changes);

        $this->report($changes, $collisions, $unchanged);

        if (! $this->option('apply')) {
            $this->newLine();
            $this->line('Nothing was written. Re-run with --apply once the report above looks right.');

            return self::SUCCESS;
        }

        return $this->apply($changes, $collisions);
    }

    /**
     * What this row's number should be.
     *
     * Reconstructed by re-running formatPhone over the stored value with the
     * row's own country, which is the only honest way to do it: the stored
     * number already carries its dial code, so the local part is whatever
     * follows it, and re-formatting that is exactly what registration would
     * do today.
     *
     * Returns null when the row cannot be reasoned about - no country, or a
     * number that does not start with the country's dial code. Those are left
     * alone and counted, rather than guessed at.
     */
    private function corrected(User $user): ?string
    {
        $country = getCountryByCode(strtoupper((string) $user->country));

        if (! is_array($country) || blank($country['dial_code'] ?? null)) {
            return null;
        }

        $dialCode = preg_replace('/\D+/', '', (string) $country['dial_code']);
        $stored   = preg_replace('/\D+/', '', (string) $user->phone);

        if ($dialCode === '' || ! str_starts_with($stored, $dialCode)) {
            return null;
        }

        return formatPhone($dialCode, substr($stored, strlen($dialCode)));
    }

    /**
     * Corrections that would land on a number some other row already holds -
     * either one that is not changing, or another correction heading for the
     * same value.
     *
     * @param  array<int, array<string, mixed>> $changes
     * @return array<int, string>
     */
    private function collisions(array $changes): array
    {
        if ($changes === []) {
            return [];
        }

        $targets = array_column($changes, 'to');

        // Rows that already hold one of the target numbers and are not
        // themselves moving off it.
        $taken = User::query()
            ->whereIn('phone', $targets)
            ->whereNotIn('id', array_keys($changes))
            ->pluck('phone', 'id')
            ->all();

        $takenNumbers = array_map('strval', array_values($taken));

        // Two corrections heading for the same number.
        $counts     = array_count_values($targets);
        $collisions = [];

        foreach ($changes as $id => $change) {
            if (in_array($change['to'], $takenNumbers, true)) {
                $collisions[$id] = 'already held by another account';

                continue;
            }

            if (($counts[$change['to']] ?? 0) > 1) {
                $collisions[$id] = 'two accounts normalise to the same number';
            }
        }

        return $collisions;
    }

    /**
     * @param array<int, array<string, mixed>> $changes
     * @param array<int, string>               $collisions
     */
    private function report(array $changes, array $collisions, int $unchanged): void
    {
        $verified = count(array_filter($changes, static fn (array $c): bool => (bool) $c['verified']));

        $this->newLine();
        $this->info('Stored phone numbers');
        $this->table(['', 'Rows'], [
            ['Already correct, or not reconstructable', $unchanged],
            ['Would change', count($changes)],
            ['  of those, currently verified', $verified],
            ['Would collide, and are refused', count($collisions)],
        ]);

        $limit = (int) $this->option('limit');

        if ($limit > 0 && $changes !== []) {
            $rows = [];

            foreach (array_slice($changes, 0, $limit, true) as $id => $change) {
                $rows[] = [
                    $id,
                    $change['country'],
                    $change['from'],
                    $change['to'],
                    $change['verified'] ? 'yes' : 'no',
                    $collisions[$id] ?? '',
                ];
            }

            $this->newLine();
            $this->table(['User', 'Country', 'Stored now', 'Would become', 'Verified', 'Collision'], $rows);
        }

        if ($collisions !== []) {
            $this->newLine();
            $this->warn(sprintf(
                '%d row(s) cannot be corrected without taking a number another account holds. They are listed above when --limit is set, and are skipped by --apply unless --skip-collisions is given.',
                count($collisions),
            ));
        }
    }

    /**
     * @param array<int, array<string, mixed>> $changes
     * @param array<int, string>               $collisions
     */
    private function apply(array $changes, array $collisions): int
    {
        if ($collisions !== [] && ! $this->option('skip-collisions')) {
            $this->error('Refusing to write while collisions exist. Resolve them, or re-run with --skip-collisions to correct everything else and leave these.');

            return self::FAILURE;
        }

        $written = 0;

        DB::transaction(function () use ($changes, $collisions, &$written): void {
            foreach ($changes as $id => $change) {
                if (isset($collisions[$id])) {
                    continue;
                }

                /*
                 * The number only. phone_verified_at is deliberately untouched
                 * - see the class docblock for why keeping it is a decision
                 * rather than an oversight.
                 */
                User::query()->whereKey($id)->update(['phone' => $change['to']]);

                $written++;
            }
        });

        $this->newLine();
        $this->info(sprintf('Corrected %d row(s). %d left alone.', $written, count($collisions)));

        return self::SUCCESS;
    }
}
