<?php

declare(strict_types=1);

namespace App\Support\AdminSearch;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The command palette's search, one scope at a time or all of them at once.
 *
 * Permission is settled here, before any searcher runs: a scope the admin has
 * no right to is not "empty", it does not exist for them, and the palette
 * draws no chip for it. Each searcher is also fenced on its own — a scope that
 * throws is logged and left out rather than taking the whole answer with it,
 * because "no transactions matched" is a worse outcome than "the palette is
 * down" only if it is silent, and it is logged.
 */
final class GlobalSearch
{
    public const SCOPE_ALL = 'all';

    public const DEFAULT_LIMIT = 5;

    public const MAX_LIMIT = 10;

    /** @var list<Searcher> */
    private readonly array $searchers;

    /**
     * @param list<string> $permissions the admin's granted permission names
     */
    public function __construct(private readonly array $permissions)
    {
        $this->searchers = [
            new PageSearcher($permissions),
            new UserSearcher,
            new TransactionSearcher,
        ];
    }

    /**
     * Every scope key the endpoint accepts, whoever is asking.
     *
     * @return list<string>
     */
    public static function scopes(): array
    {
        return [self::SCOPE_ALL, PageSearcher::KEY, UserSearcher::KEY, TransactionSearcher::KEY];
    }

    /**
     * The chips this admin gets: "All" and every scope they may search.
     *
     * @return list<array{key: string, label: string}>
     */
    public function availableScopes(): array
    {
        $scopes = [['key' => self::SCOPE_ALL, 'label' => __('All')]];

        foreach ($this->searchers as $searcher) {
            if ($this->granted($searcher->permission())) {
                $scopes[] = ['key' => $searcher->key(), 'label' => $searcher->label()];
            }
        }

        return $scopes;
    }

    /**
     * @return list<array{key: string, label: string, count: int, items: list<array<string, mixed>>}>
     */
    public function run(string $scope, string $query, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit  = max(1, min(self::MAX_LIMIT, $limit));
        $groups = [];

        foreach ($this->searchers as $searcher) {
            if ($scope !== self::SCOPE_ALL && $scope !== $searcher->key()) {
                continue;
            }

            if (! $this->granted($searcher->permission())) {
                continue;
            }

            try {
                $items = $searcher->search($query, $limit);
            } catch (Throwable $e) {
                Log::warning('Admin search scope failed', [
                    'scope' => $searcher->key(),
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($items === []) {
                continue;
            }

            $groups[] = [
                'key'   => $searcher->key(),
                'label' => $searcher->label(),
                'count' => count($items),
                'items' => $items,
            ];
        }

        return $groups;
    }

    private function granted(?string $permission): bool
    {
        return $permission === null || in_array($permission, $this->permissions, true);
    }
}
