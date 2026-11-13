<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `bucket_at` was the first NOT NULL timestamp column on the table with
     * no default. On MySQL 5.7 and MariaDB before 10.10 (the shared-host
     * defaults, where explicit_defaults_for_timestamp is off) the server
     * silently adds DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP to
     * such a column, so every `increment('count')` on a rollup bucket moved
     * its slot to "now". The next capture in the same five-minute window then
     * missed the row and opened another — one bucket per event, and the
     * Overview chart degraded to per-second keys. A DATETIME column carries
     * no such behaviour. SQLite never had it.
     */
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        if (! Schema::hasTable('error_stat_buckets') || ! Schema::hasColumn('error_stat_buckets', 'bucket_at')) {
            return;
        }

        if (Schema::getColumnType('error_stat_buckets', 'bucket_at') !== 'timestamp') {
            return;
        }

        Schema::table('error_stat_buckets', function (Blueprint $table) {
            $table->dateTime('bucket_at')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        // A DATETIME slot column is correct on every server; nothing to undo.
    }
};
