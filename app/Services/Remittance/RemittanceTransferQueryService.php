<?php

namespace App\Services\Remittance;

use App\Enums\RemittanceStatus;
use App\Models\RemittanceTransfer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class RemittanceTransferQueryService
{
    /**
     * Build the canonical admin query so the list and export cannot drift.
     *
     * @param  array<string, mixed>        $filters
     * @return Builder<RemittanceTransfer>
     */
    public function filtered(array $filters): Builder
    {
        return RemittanceTransfer::query()
            ->with(['user', 'beneficiary', 'corridor'])
            ->when(
                filled($filters['status'] ?? null),
                fn (Builder $query): Builder => $query->where('status', $filters['status']),
            )
            ->when(
                filled($filters['gateway'] ?? null),
                fn (Builder $query): Builder => $query->where('payout_gateway', $filters['gateway']),
            )
            ->when(
                filled($filters['dispatch_state'] ?? null),
                fn (Builder $query): Builder => $query->where('dispatch_state', $filters['dispatch_state']),
            )
            ->when(
                filled($filters['corridor_id'] ?? null),
                fn (Builder $query): Builder => $query->where('corridor_id', $filters['corridor_id']),
            )
            ->when(
                filled($filters['date_from'] ?? null),
                fn (Builder $query): Builder => $query->where(
                    'created_at',
                    '>=',
                    CarbonImmutable::createFromFormat('Y-m-d', (string) $filters['date_from'])->startOfDay(),
                ),
            )
            ->when(
                filled($filters['date_to'] ?? null),
                fn (Builder $query): Builder => $query->where(
                    'created_at',
                    '<=',
                    CarbonImmutable::createFromFormat('Y-m-d', (string) $filters['date_to'])->endOfDay(),
                ),
            )
            ->when(
                filled($filters['search'] ?? null),
                function (Builder $query) use ($filters): void {
                    $term = '%'.(string) $filters['search'].'%';

                    $query->where(function (Builder $query) use ($term): void {
                        $query->where('reference', 'like', $term)
                            ->orWhere('gateway_reference', 'like', $term)
                            ->orWhere('recipient_snapshot->full_name', 'like', $term)
                            ->orWhereHas('user', fn (Builder $user): Builder => $user
                                ->where('email', 'like', $term)
                                ->orWhere('first_name', 'like', $term)
                                ->orWhere('last_name', 'like', $term))
                            ->orWhereHas('beneficiary', fn (Builder $beneficiary): Builder => $beneficiary
                                ->where('first_name', 'like', $term)
                                ->orWhere('last_name', 'like', $term));
                    });
                },
            );
    }

    /**
     * @return array{scheduled: int, ambiguous: int, in_flight: int, stuck: int, definitively_failed: int}
     */
    public function operationalSummary(): array
    {
        $dispatchCounts = RemittanceTransfer::query()
            ->select('dispatch_state')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('dispatch_state')
            ->pluck('aggregate', 'dispatch_state');

        $inFlight = RemittanceTransfer::query()
            ->whereIn('status', [RemittanceStatus::Submitted->value, RemittanceStatus::Processing->value])
            ->count();
        $stuckCutoff = now()->subMinutes(max(1, (int) config('remittance.reconciliation.stuck_after_minutes', 30)));
        $stuck       = RemittanceTransfer::query()
            ->where('payout_gateway', '!=', 'manual')
            ->where(function (Builder $query) use ($stuckCutoff): void {
                $query->where(function (Builder $query) use ($stuckCutoff): void {
                    $query->whereIn('status', [RemittanceStatus::Submitted->value, RemittanceStatus::Processing->value])
                        ->where('created_at', '<=', $stuckCutoff);
                })->orWhere(function (Builder $query) use ($stuckCutoff): void {
                    $query->where('status', RemittanceStatus::Pending->value)
                        ->where('dispatch_state', 'ambiguous')
                        ->where('last_dispatch_at', '<=', $stuckCutoff);
                });
            })
            ->count();

        return [
            'scheduled'           => (int) $dispatchCounts->get('scheduled', 0),
            'ambiguous'           => (int) $dispatchCounts->get('ambiguous', 0),
            'in_flight'           => $inFlight,
            'stuck'               => $stuck,
            'definitively_failed' => (int) $dispatchCounts->get('definitively_failed', 0),
        ];
    }
}
