<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment methods can be put on live exchange rates the same way currencies
 * can, and `currency:refresh-rates` has always refreshed both. Only currencies
 * recorded *when* they were last priced, so a method's rate could go stale
 * behind a dashboard that had no way to notice.
 *
 * Existing rows are left NULL: the column starts unknown and fills in on the
 * first refresh, rather than claiming a freshness that was never measured.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = ['deposit_methods', 'withdraw_methods'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'conversion_rate_updated_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $column = $blueprint->timestamp('conversion_rate_updated_at')->nullable();

                if (Schema::hasColumn($table, 'conversion_rate')) {
                    $column->after('conversion_rate');
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'conversion_rate_updated_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('conversion_rate_updated_at');
            });
        }
    }
};
