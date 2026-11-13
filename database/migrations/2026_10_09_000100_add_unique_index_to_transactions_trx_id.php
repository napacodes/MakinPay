<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make `transactions.trx_id` unique at the database.
 *
 * Every settlement path — gateway callbacks, admin approval, refunds, the
 * merchant API — finds its row with `where('trx_id', ...)`. The model has
 * always generated the id with a "random until unused" loop, but a loop is
 * a check-then-write: two requests racing through `creating` can both see
 * the id as free. A callback that then settles "the" transaction with that
 * id picks one of two rows. The index turns that race into a refused insert.
 *
 * The migration refuses, rather than silently skipping, when an existing
 * installation already holds duplicates: the operator has to look at those
 * rows, and a migration that quietly leaves the promise unkept is worse
 * than one that stops and says which ids collide.
 */
return new class extends Migration
{
    private const INDEX = 'transactions_trx_id_unique';

    public function up(): void
    {
        if ($this->indexExists('transactions', self::INDEX)) {
            return;
        }

        $duplicates = DB::table('transactions')
            ->select('trx_id')
            ->whereNotNull('trx_id')
            ->groupBy('trx_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('trx_id');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Cannot add a unique index to transactions.trx_id: %d duplicated id(s) exist (%s). '
                .'Resolve those rows, then run the migration again.',
                $duplicates->count(),
                $duplicates->take(10)->implode(', '),
            ));
        }

        Schema::table('transactions', function (Blueprint $table): void {
            $table->unique('trx_id', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! $this->indexExists('transactions', self::INDEX)) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropUnique(self::INDEX);
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('$table')");

            return collect($indexes)->contains(function (object $index) use ($indexName): bool {
                return isset($index->name) && $index->name === $indexName;
            });
        }

        if ($driver === 'mysql') {
            return DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $indexName)
                ->exists();
        }

        return false;
    }
};
