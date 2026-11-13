<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt the scheduler made at a scheduled payment: what it moved,
 * or why it did not.
 */
class ScheduledPaymentRun extends Model
{
    public const STATUSES = ['success', 'failed', 'skipped'];

    protected $fillable = ['scheduled_payment_id', 'due_at', 'attempt', 'executed_at', 'status', 'transaction_id', 'reference', 'amount', 'currency', 'reason'];

    protected function casts(): array
    {
        return [
            'due_at'      => 'datetime',
            'executed_at' => 'datetime',
            'attempt'     => 'integer',
            'amount'      => 'decimal:8',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ScheduledPayment::class, 'scheduled_payment_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
