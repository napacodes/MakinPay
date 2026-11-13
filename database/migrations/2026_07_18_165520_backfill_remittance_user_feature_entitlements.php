<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $description = 'Allows users to send international remittance transfers.';

        DB::table('users')
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($users) use ($description): void {
                $userIds         = $users->pluck('id');
                $existingUserIds = DB::table('user_features')
                    ->where('feature', 'remittance')
                    ->whereIn('user_id', $userIds)
                    ->pluck('user_id')
                    ->all();

                $now  = now();
                $rows = $userIds
                    ->diff($existingUserIds)
                    ->map(fn (int $userId): array => [
                        'user_id'     => $userId,
                        'feature'     => 'remittance',
                        'description' => $description,
                        'status'      => true,
                        'sort_order'  => 10,
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('user_features')
            ->where('feature', 'remittance')
            ->where('description', 'Allows users to send international remittance transfers.')
            ->delete();
    }
};
