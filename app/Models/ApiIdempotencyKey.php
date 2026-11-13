<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded attempt at a money-moving API call.
 *
 * @property int    $user_id
 * @property string $idempotency_key
 * @property string $request_hash
 */
class ApiIdempotencyKey extends Model
{
    use MassPrunable;

    protected $fillable = [
        'user_id',
        'idempotency_key',
        'request_hash',
        'response_status',
        'response_content_type',
        'response_body',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at'      => 'datetime',
            'response_status' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether this row still speaks for the attempt it recorded.
     *
     * An expired row is not a replay: the window has closed, and answering a
     * day-old retry with a day-old response would be worse than making the
     * caller ask again.
     */
    public function isReplayable(): bool
    {
        return $this->response_status !== null && $this->expires_at?->isFuture() === true;
    }

    /**
     * Expired rows are DELETED, not merely ignored.
     *
     * `expires_at` only ever meant "will not be replayed"; nothing removed
     * the row, so the table grew without bound. Each row holds
     * `response_body` - the full JSON answer to a money call, with recipient
     * names, references and amounts - so this is a retention question before
     * it is a disk one.
     *
     * `<=` rather than `<`, matching MerchantApiIdempotency: a key at exactly
     * its expiry is already past replaying.
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', now());
    }
}
