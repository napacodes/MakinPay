<?php

declare(strict_types=1);

namespace App\Models\P2P;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One customer choosing not to see another's ads. The blocked trader is
 * not told, and nothing else about them changes.
 */
class Block extends Model
{
    protected $table = 'p2p_blocks';

    protected $fillable = [
        'user_id',
        'blocked_user_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function blocked(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_user_id');
    }

    /**
     * The ids of everyone this customer has blocked, as a subquery.
     */
    public static function blockedBy(int $userId): Builder
    {
        return self::query()->select('blocked_user_id')->where('user_id', $userId);
    }
}
