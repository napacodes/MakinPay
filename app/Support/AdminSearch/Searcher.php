<?php

declare(strict_types=1);

namespace App\Support\AdminSearch;

/**
 * One scope of the admin command palette.
 *
 * A searcher answers for one kind of thing an operator might be looking for —
 * a page, a customer, a ledger entry — and says which permission an admin
 * needs before it will answer at all. Adding a searchable entity is adding a
 * class here and a chip in the palette; there is never a second search box.
 *
 * Every item is the same shape whatever it describes, so the palette draws
 * all of them with one row:
 *
 * @phpstan-type SearchItem array{
 *     type: string,
 *     id: string,
 *     title: string,
 *     sub: string,
 *     url: string,
 *     icon: string|null,
 *     initials: string|null,
 *     badge: string|null,
 *     tone: string
 * }
 */
interface Searcher
{
    /** The scope key the palette and the endpoint agree on: pages, users, transactions. */
    public function key(): string;

    /** The group heading, already translated. */
    public function label(): string;

    /** The permission an admin needs for this scope to exist for them, or null for everyone. */
    public function permission(): ?string;

    /**
     * The matches for a query, at most $limit of them.
     *
     * An empty query is a request for the scope's own shortcuts — the pages
     * searcher answers it with the top-level screens, the record searchers
     * answer it with nothing.
     *
     * @return list<SearchItem>
     */
    public function search(string $query, int $limit): array;
}
