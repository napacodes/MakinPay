<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every existing account the layer-B `mass_payment` key.
 *
 * User::hasFeature() is false when the row is missing, so without this every
 * account created before the upgrade would be refused by the
 * `feature:mass_payment` middleware even after the admin switches the module
 * on. Only missing rows are inserted, switched on, so a re-run is a no-op and
 * an administrator's per-user choice is never overwritten.
 */
return new class extends Migration
{
    private const FEATURE = 'mass_payment';

    private const DESCRIPTION = 'Allows paying many recipients at once from one wallet.';

    /**
     * The key's position in config/userFeatures.php, which is what
     * syncWithConfigForUser() writes as sort_order.
     */
    private const SORT_ORDER = 11;

    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('user_features')) {
            return;
        }

        DB::table('users')
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($users): void {
                $userIds         = $users->pluck('id');
                $existingUserIds = DB::table('user_features')
                    ->where('feature', self::FEATURE)
                    ->whereIn('user_id', $userIds)
                    ->pluck('user_id')
                    ->all();

                $now  = now();
                $rows = $userIds
                    ->diff($existingUserIds)
                    ->map(fn (int $userId): array => [
                        'user_id'     => $userId,
                        'feature'     => self::FEATURE,
                        'description' => self::DESCRIPTION,
                        'status'      => true,
                        'sort_order'  => self::SORT_ORDER,
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ])
                    ->values()
                    ->all();

                if ($rows !== []) {
                    DB::table('user_features')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_features')) {
            return;
        }

        DB::table('user_features')->where('feature', self::FEATURE)->delete();
    }
};
