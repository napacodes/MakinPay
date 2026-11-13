<?php

declare(strict_types=1);

namespace App\Traits\Models;

use Illuminate\Support\Facades\DB;

/**
 * Cache invalidation that survives the transaction it was issued in.
 *
 * A model that caches itself under a `rememberForever` key clears that key
 * from a `saved` or `deleted` hook. Those hooks fire while the writer's
 * transaction is still open, and until it commits the new row is invisible to
 * every other connection. A read arriving in that window misses the key it
 * just cleared, loads the row as it stood *before* the write, and stores that
 * under a key nothing will clear again — so the stale value is served for the
 * life of the cache, while the writer and any uncached screen show the new
 * one. The two disagree permanently and the write looks like it never landed.
 *
 * Repeating the flush once the write is durable closes the window. Outside a
 * transaction there is no window to close, so the flush already issued is the
 * whole of the work and nothing further is registered.
 */
trait FlushesCacheAfterCommit
{
    /**
     * Run a cache flush now, and again once any open transaction commits.
     */
    protected static function flushCacheAfterCommit(callable $flush): void
    {
        $flush();

        $connection = DB::connection((new static)->getConnectionName());

        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($flush);
        }
    }
}
