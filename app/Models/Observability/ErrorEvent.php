<?php

namespace App\Models\Observability;

use App\Enums\Observability\ErrorLevel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One row per occurrence, carrying the heavy JSON.
 *
 * Everything in context, request and state has already passed the redaction
 * pipeline before it reaches this model. Nothing here scrubs at read time,
 * because a record that needs scrubbing on the way out was already written to
 * disk unscrubbed, and disk is exactly where it must never be.
 *
 * There is no updated_at: an occurrence is a fact about a moment, and a fact
 * that can be edited is not one.
 */
class ErrorEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'event_id',
        'error_group_id',
        'level',
        'context',
        'request',
        'state',
        'redaction_report',
        'trace_id',
        'span_id',
        'release',
        'user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'level'            => ErrorLevel::class,
            'context'          => 'array',
            'request'          => 'array',
            'state'            => 'array',
            'redaction_report' => 'array',
            'created_at'       => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            if (blank($event->event_id)) {
                $event->event_id = (string) Str::ulid();
            }
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ErrorGroup::class, 'error_group_id');
    }

    public function getRouteKeyName(): string
    {
        return 'event_id';
    }
}
