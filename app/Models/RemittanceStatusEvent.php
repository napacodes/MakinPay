<?php

namespace App\Models;

use Database\Factories\RemittanceStatusEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class RemittanceStatusEvent extends Model
{
    /** @use HasFactory<RemittanceStatusEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'transfer_id',
        'from_status',
        'to_status',
        'note',
        'actor_type',
        'actor_id',
        'source',
        'external_event_id',
        'payload_hash',
        'context',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'actor_id'    => 'integer',
            'context'     => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Remittance audit events are append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Remittance audit events cannot be deleted.');
        });
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(RemittanceTransfer::class, 'transfer_id');
    }
}
