<?php

namespace App\Models\Observability;

use App\Enums\Observability\ErrorLevel;
use App\Enums\Observability\ErrorSource;
use App\Enums\Observability\ErrorStatus;
use App\Models\Admin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One row per fingerprint: one bug, not one occurrence.
 *
 * Every number this model exposes to the Issues list is a stored counter kept
 * up to date on ingest. Nothing here counts rows in error_events, because that
 * is the one query that would make the list slow on the installs that need it
 * most.
 */
class ErrorGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'fingerprint',
        'group_id',
        'title',
        'exception_class',
        'message',
        'error_code',
        'culprit',
        'level',
        'source',
        'status',
        'first_seen',
        'last_seen',
        'event_count',
        'affected_user_count',
        'assignee_id',
        'tags',
        'resolved_at',
        'resolved_release',
        'first_release',
        'last_release',
    ];

    protected function casts(): array
    {
        return [
            'level'               => ErrorLevel::class,
            'source'              => ErrorSource::class,
            'status'              => ErrorStatus::class,
            'tags'                => 'array',
            'first_seen'          => 'datetime',
            'last_seen'           => 'datetime',
            'resolved_at'         => 'datetime',
            'event_count'         => 'integer',
            'affected_user_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $group): void {
            if (blank($group->group_id)) {
                $group->group_id = (string) Str::ulid();
            }
        });
    }

    public function events(): HasMany
    {
        return $this->hasMany(ErrorEvent::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assignee_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(ErrorDeliveryLog::class, 'error_group_id');
    }

    /**
     * Groups that still need somebody's attention: everything except
     * resolved and ignored.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ErrorStatus::openValues());
    }

    public function getRouteKeyName(): string
    {
        return 'group_id';
    }

    /**
     * Whether the group is muted. Ignored means "stop alerting me", so the
     * row stays visible and keeps counting; only notification stops.
     */
    public function isMuted(): bool
    {
        return $this->status === ErrorStatus::Ignored;
    }
}
