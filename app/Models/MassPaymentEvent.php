<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One move of a mass payment batch, or of one of its lines: who made it, from
 * what, to what, and why. Never edited or deleted afterwards.
 *
 * `row_id` is null for a batch-level event. `actor_type` is one of system,
 * user or admin.
 */
class MassPaymentEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'mass_payment_batch_id',
        'row_id',
        'external_event_id',
        'from_status',
        'to_status',
        'actor_type',
        'actor_id',
        'note',
        'payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'row_id'     => 'integer',
            'actor_id'   => 'integer',
            'payload'    => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            $event->created_at ??= now();
        });

        static::updating(function (): never {
            throw new LogicException('Mass payment events are append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Mass payment events cannot be deleted.');
        });
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MassPaymentBatch::class, 'mass_payment_batch_id');
    }

    public function row(): BelongsTo
    {
        return $this->belongsTo(MassPaymentRow::class, 'row_id');
    }
}
