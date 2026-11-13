<?php

namespace App\Jobs\Observability;

use App\Enums\Observability\ErrorStatus;
use App\Models\Observability\ErrorGroup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Applies one triage action to many error groups.
 *
 * The same class runs both ways: called directly for a small selection, and
 * dispatched for a large one. Keeping one implementation means the queued path
 * cannot drift from the inline path and quietly do something different.
 *
 * It is idempotent — setting a status that is already set is a no-op — so a
 * queue retry after a partial run finishes the job rather than corrupting it.
 */
class ApplyBulkIssueAction implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Updated in chunks so a large selection cannot hold one long lock on a
     * table the collector is still writing to.
     */
    private const CHUNK = 200;

    /**
     * @param list<string> $groupIds
     */
    public function __construct(
        private array $groupIds,
        private string $action,
        private ?int $adminId = null,
    ) {}

    public function handle(): void
    {
        foreach (array_chunk($this->groupIds, self::CHUNK) as $chunk) {
            ErrorGroup::query()
                ->whereIn('group_id', $chunk)
                ->update($this->attributes());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(): array
    {
        return match ($this->action) {
            'resolve' => [
                'status'      => ErrorStatus::Resolved->value,
                'resolved_at' => now(),
            ],
            'ignore' => [
                'status' => ErrorStatus::Ignored->value,
            ],
            'assign' => [
                'assignee_id' => $this->adminId,
            ],
            default => [],
        };
    }
}
