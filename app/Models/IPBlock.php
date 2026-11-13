<?php

namespace App\Models;

use App\Services\Security\IpAbuseGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class IPBlock extends Model
{
    /** Written by an administrator on the IP deny list screen. */
    public const SOURCE_MANUAL = 'manual';

    /** Written by {@see IpAbuseGuard}. */
    public const SOURCE_AUTO = 'auto';

    protected $table = 'ip_blocks';

    protected $fillable = [
        'ip_address',
        'reason',
        'source',
        'strikes',
        'signals',
        'blocked_at',
        'expires_at',
    ];

    /**
     * The column defaults to CURRENT_TIMESTAMP in the schema, so a row
     * created before the admin screen existed still reads back as a date.
     */
    protected $casts = [
        'blocked_at' => 'datetime',
        'expires_at' => 'datetime',
        'strikes'    => 'integer',
        'signals'    => 'array',
    ];

    /**
     * How long this block was written for, in minutes.
     *
     * Null for a manual block, which has no window. The drawer shows the
     * remaining time against this, so "48m left" means something.
     */
    public function windowMinutes(): ?int
    {
        $blockedAt = $this->getAttribute('blocked_at');
        $expiresAt = $this->getAttribute('expires_at');

        if ($blockedAt === null || $expiresAt === null) {
            return null;
        }

        return max(1, (int) $blockedAt->diffInMinutes($expiresAt, true));
    }

    /**
     * Minutes until it lapses on its own, or null when it never will.
     */
    public function minutesRemaining(): ?int
    {
        $expiresAt = $this->getAttribute('expires_at');

        if ($expiresAt === null) {
            return null;
        }

        return max(0, (int) now()->diffInMinutes($expiresAt, false));
    }

    /**
     * An automatic block is a cool-off, not a verdict: it lapses on its own
     * so a shared address, a mistyped password storm or a proxy that once
     * carried an attacker does not lock real customers out for good. A
     * manual block has no expiry and stays until an administrator removes it.
     */
    public function isExpired(): bool
    {
        $expiresAt = $this->getAttribute('expires_at');

        return $expiresAt !== null && $expiresAt->isPast();
    }

    public function isAutomatic(): bool
    {
        return $this->getAttribute('source') === self::SOURCE_AUTO;
    }

    /**
     * Rows that should still refuse traffic right now.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $window): void {
            $window->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }
}
