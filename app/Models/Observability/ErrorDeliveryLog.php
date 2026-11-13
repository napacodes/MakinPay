<?php

namespace App\Models\Observability;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every delivery attempt, including the ones that were never sent.
 *
 * Throttled and quiet-hours rows are written on purpose. Discarding them would
 * make throttling indistinguishable from a broken channel, and "why was I not
 * paged?" is the question this table exists to answer.
 */
class ErrorDeliveryLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RETRYING = 'retrying';

    public const STATUS_THROTTLED = 'throttled';

    public const STATUS_QUIET_HOURS = 'quiet_hours';

    public const STATUS_DEDUPED = 'deduped';

    public const STATUS_SAMPLED_OUT = 'sampled_out';

    protected $table = 'error_delivery_log';

    /**
     * Write down a held-back alert once per window, not once per occurrence.
     *
     * A storm during quiet hours or inside a throttle window used to add a
     * row for every event on every channel, which is thousands of identical
     * lines saying the same thing.
     */
    public static function recordGate(int $groupId, string $channelKey, string $status, int $windowMinutes = 60): void
    {
        $recent = static::query()
            ->where('error_group_id', $groupId)
            ->where('channel_key', $channelKey)
            ->where('status', $status)
            ->where('created_at', '>=', now()->subMinutes($windowMinutes))
            ->exists();

        if ($recent) {
            return;
        }

        static::query()->create([
            'error_group_id' => $groupId,
            'channel_key'    => $channelKey,
            'status'         => $status,
            'created_at'     => now(),
        ]);
    }

    protected $fillable = [
        'error_group_id',
        'channel_key',
        'status',
        'attempt',
        'response_code',
        'response_summary',
        'duration_ms',
        'acknowledged_at',
        'acknowledged_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt'         => 'integer',
            'response_code'   => 'integer',
            'duration_ms'     => 'integer',
            'acknowledged_at' => 'datetime',
            'created_at'      => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ErrorGroup::class, 'error_group_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(ErrorNotificationChannel::class, 'channel_key', 'key');
    }
}
