<?php

namespace App\Jobs;

use App\Services\Remittance\RemittanceService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ReconcileRemittancePayout implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 300;

    public function __construct(public int $transferId) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function uniqueId(): string
    {
        return (string) $this->transferId;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('remittance-reconcile:'.$this->transferId))
                ->releaseAfter(15)
                ->expireAfter(120),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(RemittanceService $remittance): void
    {
        $remittance->reconcileProviderStatusById($this->transferId);
    }
}
