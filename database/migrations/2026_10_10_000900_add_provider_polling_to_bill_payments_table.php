<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * When bill-payment:reconcile next asks a provider about a payment it is
 * waiting on, and how often it already asked.
 *
 *   provider_check_attempts   status checks made while awaiting the provider
 *   provider_checked_at       the last of them
 *   provider_next_check_at    when the next one is due (the backoff)
 *   provider_final_status_at  when the provider said a final answer would be
 *                             available, capped at the reconcile deadline
 *
 * Added, never backfilled: a payment from before this existed has no schedule,
 * and the reconcile command treats a null next check as due now. Every
 * addition is guarded so a partial earlier run, or a second run, is harmless.
 */
return new class extends Migration
{
    private const INDEX = 'bill_payments_dispatch_next_check_index';

    public function up(): void
    {
        if (! Schema::hasTable('bill_payments')) {
            return;
        }

        Schema::table('bill_payments', function (Blueprint $table): void {
            if (! Schema::hasColumn('bill_payments', 'provider_check_attempts')) {
                $table->unsignedSmallInteger('provider_check_attempts')->default(0)->after('submitted_at');
            }

            if (! Schema::hasColumn('bill_payments', 'provider_checked_at')) {
                $table->timestamp('provider_checked_at')->nullable()->after('provider_check_attempts');
            }

            if (! Schema::hasColumn('bill_payments', 'provider_next_check_at')) {
                $table->timestamp('provider_next_check_at')->nullable()->after('provider_checked_at');
            }

            if (! Schema::hasColumn('bill_payments', 'provider_final_status_at')) {
                $table->timestamp('provider_final_status_at')->nullable()->after('provider_next_check_at');
            }
        });

        // The reconcile command reads (dispatch_state, provider_next_check_at)
        // every five minutes, so the due payments are found without a scan.
        Schema::table('bill_payments', function (Blueprint $table): void {
            if (! $this->hasIndex(self::INDEX)) {
                $table->index(['dispatch_state', 'provider_next_check_at'], self::INDEX);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bill_payments')) {
            return;
        }

        Schema::table('bill_payments', function (Blueprint $table): void {
            if ($this->hasIndex(self::INDEX)) {
                $table->dropIndex(self::INDEX);
            }
        });

        Schema::table('bill_payments', function (Blueprint $table): void {
            foreach (['provider_check_attempts', 'provider_checked_at', 'provider_next_check_at', 'provider_final_status_at'] as $column) {
                if (Schema::hasColumn('bill_payments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * Index existence is not part of Laravel's Schema facade, and an upgraded
     * installation may already carry this one from a partial run.
     */
    private function hasIndex(string $name): bool
    {
        foreach (Schema::getIndexes('bill_payments') as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
};
