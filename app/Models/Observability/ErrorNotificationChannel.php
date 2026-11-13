<?php

namespace App\Models\Observability;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One configured destination for alerts.
 *
 * Credentials are encrypted rather than stored plainly: a bot token or an
 * incoming-webhook URL is enough to post as the platform in its own incident
 * channel, and a database dump handed to support should not carry that.
 */
class ErrorNotificationChannel extends Model
{
    use HasFactory;

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_RETRYING = 'retrying';

    public const STATUS_FAILING = 'failing';

    public const STATUS_NOT_CONNECTED = 'not_connected';

    protected $fillable = [
        'key',
        'driver',
        'label',
        'is_enabled',
        'credentials',
        'settings',
        'status',
        'consecutive_failures',
        'paused_until',
        'last_delivery_at',
        'last_delivery_status',
        'last_delivery_ms',
    ];

    protected $hidden = [
        'credentials',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled'           => 'boolean',
            'credentials'          => 'encrypted:array',
            'settings'             => 'array',
            'consecutive_failures' => 'integer',
            'paused_until'         => 'datetime',
            'last_delivery_at'     => 'datetime',
            'last_delivery_ms'     => 'integer',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(ErrorDeliveryLog::class, 'channel_key', 'key');
    }

    /**
     * A tripped circuit breaker. Five consecutive failures pause the channel
     * for fifteen minutes so a dead endpoint stops consuming queue workers
     * that the healthy channels still need.
     */
    public function isPaused(): bool
    {
        return $this->paused_until !== null && $this->paused_until->isFuture();
    }

    public function isDeliverable(): bool
    {
        return $this->is_enabled && ! $this->isPaused();
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }
}
