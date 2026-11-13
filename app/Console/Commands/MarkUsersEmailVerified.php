<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Mark existing accounts as email-verified before switching verification on.
 *
 * Turning on "Require Email Verification" gates everyone whose
 * `email_verified_at` is null — which, on a site where verification never
 * worked, is most of the account base. They can each resend a link and verify,
 * and for many sites that is the right answer. This command exists for the
 * operator who would rather draw a line: everyone who registered before today
 * is accepted as-is, and the requirement applies to new signups only.
 *
 * That is a claim about other people's addresses, so it is deliberately
 * awkward to fire by accident:
 *
 *   - a scope is required; there is no "just do everything" default
 *   - without --apply it only reports
 *   - with --apply it still asks, unless --force says not to
 *
 * It writes `email_verified_at` directly and does NOT dispatch the Verified
 * event. AwardSignupBonusOnVerified listens to that event, so firing it here
 * would credit a signup bonus to every account in the range — a financial
 * action nobody asked for. The admin panel's own per-user toggle writes the
 * column the same way.
 */
class MarkUsersEmailVerified extends Command
{
    protected $signature = 'users:mark-verified
                            {--before= : Accounts created strictly before this date, e.g. 2026-08-28.}
                            {--email=* : One or more specific addresses.}
                            {--all : Every unverified account.}
                            {--apply : Write the change. Without this the command only reports.}
                            {--force : Skip the confirmation prompt, for scripted runs.}
                            {--limit=25 : Rows to show in the preview table.}';

    protected $description = 'Accept existing accounts as email-verified so enabling verification does not gate them';

    public function handle(): int
    {
        try {
            $query = $this->scopedQuery();
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->components->info('No unverified accounts match that scope.');

            return self::SUCCESS;
        }

        $this->preview($query, $total);

        if (! $this->option('apply')) {
            $this->components->warn('Nothing was changed. Re-run with --apply to write these values.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(sprintf('Accept %d account(s) as email-verified?', $total), false)) {
            $this->components->warn('Aborted. Nothing was changed.');

            return self::SUCCESS;
        }

        // now(), not the account's created_at: the timestamp records when an
        // administrator vouched for these addresses, which is what actually
        // happened. Chunked so a large account base does not build one
        // enormous statement.
        $verifiedAt = now();
        $changed    = 0;

        (clone $query)->select('id')->chunkById(500, function ($users) use ($verifiedAt, &$changed): void {
            $changed += User::query()
                ->whereIn('id', $users->pluck('id'))
                ->update(['email_verified_at' => $verifiedAt]);
        });

        $this->components->info(sprintf('%d account(s) are now marked email-verified.', $changed));
        $this->components->info('The Verified event was not dispatched, so no signup bonus was credited.');

        return self::SUCCESS;
    }

    /**
     * @return Builder<User>
     */
    private function scopedQuery(): Builder
    {
        $before = (string) ($this->option('before') ?? '');
        $emails = array_values(array_filter((array) $this->option('email')));
        $all    = (bool) $this->option('all');

        $scopes = (int) ($before !== '') + (int) ($emails !== []) + (int) $all;

        if ($scopes === 0) {
            throw new RuntimeException('Choose a scope: --before=DATE, --email=someone@example.com, or --all.');
        }

        if ($scopes > 1) {
            throw new RuntimeException('Use only one of --before, --email or --all.');
        }

        $query = User::query()->whereNull('email_verified_at');

        if ($emails !== []) {
            return $query->whereIn('email', $emails);
        }

        if ($all) {
            return $query;
        }

        return $query->where('created_at', '<', $this->parseBefore($before));
    }

    private function parseBefore(string $value): CarbonInterface
    {
        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            throw new RuntimeException(sprintf('Could not read "%s" as a date. Try a format like 2026-08-28.', $value));
        }
    }

    /**
     * @param Builder<User> $query
     */
    private function preview(Builder $query, int $total): void
    {
        $limit = max(1, (int) $this->option('limit'));

        $rows = (clone $query)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'name', 'email', 'created_at']);

        $this->table(
            ['ID', 'Name', 'Email', 'Registered'],
            $rows->map(fn (User $user): array => [
                $user->id,
                $user->name,
                $user->email,
                $user->created_at?->toDateString(),
            ])->all()
        );

        if ($total > $rows->count()) {
            $this->components->info(sprintf('Showing %d of %d matching account(s).', $rows->count(), $total));
        }
    }
}
