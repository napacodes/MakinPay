<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserRank;
use Illuminate\Support\Collection;

/**
 * Where a customer stands on the rank ladder.
 *
 * This lived in the @php block at the top of the rank showcase view. Lifted so
 * the app is told exactly what the website shows - which ranks are earned, the
 * current one, the next one, how far along - rather than a second reading of
 * the same rule.
 *
 * Earned means the current rank plus every rank in old_ranks: a customer who
 * dropped a tier keeps the ones already reached.
 */
final class UserRankProgress
{
    /**
     * @return array{ranks: Collection<int, UserRank>, earned: Collection<int, int>, current: ?UserRank, next: ?UserRank, completion_percent: int}
     */
    public function for(?User $user): array
    {
        $ranks = UserRank::query()
            ->where('is_active', true)
            ->orderBy('transaction_amount')
            ->get();

        $earned = collect((array) ($user?->old_ranks ?? []))
            ->push($user?->rank_id)
            ->filter()
            ->map(fn ($rankId): int => (int) $rankId)
            ->unique()
            ->values();

        $current = $ranks->firstWhere('id', $user?->rank_id)
            ?? $ranks->first(fn (UserRank $rank): bool => $earned->contains((int) $rank->id));

        $next = $ranks->first(fn (UserRank $rank): bool => ! $earned->contains((int) $rank->id));

        $total    = $ranks->count();
        $unlocked = $ranks->filter(fn (UserRank $rank): bool => $earned->contains((int) $rank->id))->count();

        return [
            'ranks'              => $ranks,
            'earned'             => $earned,
            'current'            => $current,
            'next'               => $next,
            'completion_percent' => $total > 0 ? min(100, (int) round(($unlocked / $total) * 100)) : 0,
        ];
    }
}
