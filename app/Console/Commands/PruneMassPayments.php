<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MassPayment\MassPaymentBatchStatus;
use App\Models\MassPaymentBatch;
use App\Services\MassPayment\MassPaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Tidy mass payment drafts, which never held money.
 *
 *   1. Drafts past `expires_at` become Expired.
 *   2. Discarded and expired drafts created more than `--days` ago are
 *      deleted with their lines and their audit trail, which removes the
 *      recipients' personal data. A batch that was ever confirmed, or has any
 *      line bound to a ledger row, is never deleted; the service checks that
 *      again under the batch lock.
 */
class PruneMassPayments extends Command
{
    protected $signature = 'mass-payments:prune
                            {--days=30 : Delete discarded and expired drafts created more than this many days ago}
                            {--limit=500 : How many drafts each step takes in one run (1-5000)}';

    protected $description = 'Expire mass payment drafts past their expiry and delete old discarded or expired drafts';

    public function handle(MassPaymentService $service): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $days  = max(1, (int) $this->option('days'));

        $errors = 0;

        $expired = $this->each($this->ids(MassPaymentBatch::query()
            ->where('status', MassPaymentBatchStatus::Draft->value)
            ->where('expires_at', '<', CarbonImmutable::now())
            ->orderBy('id'), $limit), $errors, static fn (int $id): bool => $service->expireDraft($id));

        $purged = $this->each($this->ids(MassPaymentBatch::query()
            ->whereIn('status', [MassPaymentBatchStatus::Discarded->value, MassPaymentBatchStatus::Expired->value])
            ->whereNull('confirmed_at')
            ->where('created_at', '<', CarbonImmutable::now()->subDays($days))
            ->orderBy('id'), $limit), $errors, static fn (int $id): bool => $service->purgeDraft($id));

        $this->info(sprintf('%d drafts expired, %d old drafts deleted, %d errored.', $expired, $purged, $errors));

        return self::SUCCESS;
    }

    /**
     * Run $action for each batch id on its own and count the ones it acted
     * on. Anything that throws is reported and the run goes on.
     *
     * @param list<int>           $ids
     * @param callable(int): bool $action
     */
    private function each(array $ids, int &$errors, callable $action): int
    {
        $done = 0;

        foreach ($ids as $id) {
            try {
                if ($action($id)) {
                    $done++;
                }
            } catch (Throwable $exception) {
                report($exception);
                $errors++;
            }
        }

        return $done;
    }

    /**
     * @param  Builder<MassPaymentBatch> $query
     * @return list<int>
     */
    private function ids(Builder $query, int $limit): array
    {
        return $query->limit($limit)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all();
    }
}
