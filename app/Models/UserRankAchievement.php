<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rank a customer reached, and when. The users table keeps which
 * ranks were earned; this keeps the date and the reward's transaction, so
 * the ladder can say "Reached 12 Aug" and the rewards hub can list it.
 */
class UserRankAchievement extends Model
{
    protected $fillable = ['user_id', 'user_rank_id', 'earned_at', 'trx_id'];

    protected function casts(): array
    {
        return ['earned_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rank(): BelongsTo
    {
        return $this->belongsTo(UserRank::class, 'user_rank_id');
    }

    /**
     * Records that $user reached $rank now, once; a row already there keeps
     * its own date.
     */
    public static function record(User $user, UserRank $rank, ?string $trxId = null): self
    {
        return self::query()->firstOrCreate(
            ['user_id' => $user->id, 'user_rank_id' => $rank->id],
            ['earned_at' => now(), 'trx_id' => $trxId],
        );
    }
}
