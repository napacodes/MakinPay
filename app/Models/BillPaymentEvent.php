<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillPayment\BillPaymentActorType;
use Database\Factories\BillPaymentEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One move of a bill payment's status or dispatch state: who made it, from
 * what, to what, and why. Written in the same database transaction as the
 * change it records, and never edited afterwards.
 *
 * `payload` never holds the token.
 */
class BillPaymentEvent extends Model
{
    /** @use HasFactory<BillPaymentEventFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'bill_payment_id',
        'from_status',
        'to_status',
        'from_dispatch_state',
        'to_dispatch_state',
        'actor_type',
        'actor_id',
        'note',
        'external_event_id',
        'payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'actor_type' => BillPaymentActorType::class,
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
            throw new LogicException('Bill payment events are append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Bill payment events cannot be deleted.');
        });
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(BillPayment::class, 'bill_payment_id');
    }
}
