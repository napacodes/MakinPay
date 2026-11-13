<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Round 11 of the app: when a customer reached each rank, a stake that
 * rolls itself over at maturity, and the cashback programme's monthly
 * claims. Every step is guarded, so an installation that already has any
 * of it is left as it is.
 *
 * Ranks already reached are backfilled without a date: the users table
 * only ever knew which ranks were earned, never when.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_rank_achievements')) {
            Schema::create('user_rank_achievements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('user_rank_id')->constrained('user_ranks')->cascadeOnDelete();
                $table->timestamp('earned_at')->nullable();
                $table->string('trx_id', 64)->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'user_rank_id']);
            });

            $this->backfillAchievements();
        }

        if (! Schema::hasTable('cashback_claims')) {
            Schema::create('cashback_claims', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('period', 7);
                $table->string('currency', 10);
                $table->decimal('spend', 24, 8)->default(0);
                $table->decimal('rate_pct', 8, 4)->default(0);
                $table->decimal('amount', 24, 8)->default(0);
                $table->string('status', 16)->default('pending');
                $table->date('claim_by');
                $table->timestamp('claimed_at')->nullable();
                $table->string('trx_id', 64)->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'period', 'currency']);
            });
        }

        Schema::table('wallet_earn_stakes', function (Blueprint $table): void {
            if (! Schema::hasColumn('wallet_earn_stakes', 'auto_restake')) {
                $table->boolean('auto_restake')->default(false)->after('return_principal');
            }
            if (! Schema::hasColumn('wallet_earn_stakes', 'restaked_into_id')) {
                $table->unsignedBigInteger('restaked_into_id')->nullable()->after('auto_restake');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wallet_earn_stakes', function (Blueprint $table): void {
            foreach (['restaked_into_id', 'auto_restake'] as $column) {
                if (Schema::hasColumn('wallet_earn_stakes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        Schema::dropIfExists('cashback_claims');
        Schema::dropIfExists('user_rank_achievements');
    }

    private function backfillAchievements(): void
    {
        if (! Schema::hasColumn('users', 'rank_id')) {
            return;
        }

        $hasOld = Schema::hasColumn('users', 'old_ranks');
        $now    = now();

        DB::table('users')
            ->select(array_values(array_filter(['id', 'rank_id', $hasOld ? 'old_ranks' : null])))
            ->where(fn ($query) => $query->whereNotNull('rank_id')->when($hasOld, fn ($q) => $q->orWhereNotNull('old_ranks')))
            ->orderBy('id')
            ->chunk(500, function ($users) use ($hasOld, $now): void {
                $rows = [];
                foreach ($users as $user) {
                    $ids   = $hasOld && $user->old_ranks ? (array) json_decode((string) $user->old_ranks, true) : [];
                    $ids[] = $user->rank_id;
                    foreach (array_unique(array_filter(array_map('intval', $ids))) as $rankId) {
                        $rows[] = ['user_id' => $user->id, 'user_rank_id' => $rankId, 'earned_at' => null, 'created_at' => $now, 'updated_at' => $now];
                    }
                }
                if ($rows !== []) {
                    DB::table('user_rank_achievements')->insertOrIgnore($rows);
                }
            });
    }
};
