<?php

namespace App\Jobs;

use App\Services\Remittance\RemittanceService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class DispatchRemittancePayout implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public int $uniqueFor = 300;

    public function __construct(public int $transferId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 15, 60, 180];
    }

    public function uniqueId(): string
    {
        return (string) $this->transferId;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('remittance-payout:'.$this->transferId))
                ->releaseAfter(10)
                ->expireAfter(120),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(RemittanceService $remittance): void
    {
        $remittance->dispatchToGatewayById($this->transferId);
    }

    public function failed(?Throwable $exception): void
    {
        app(RemittanceService::class)->recordAmbiguousDispatchFailure(
            $this->transferId,
            $exception?->getMessage() ?? 'Payout dispatch failed after all retry attempts.',
        );
    }
}
