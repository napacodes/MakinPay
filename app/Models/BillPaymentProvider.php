<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\BillPayment\SyncsBillers;
use App\Enums\BillPayment\ProviderFault;
use App\Support\Money;
use Database\Factories\BillPaymentProviderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Who pays the biller for a bill payment, and the fee rule billers inherit.
 *
 * `driver` names a key of config('bill_payments.drivers'). API credentials, once
 * a provider needs them, live in the matching `plugins` row (`plugin_id`), the
 * pattern mobile recharge and virtual-card providers already follow.
 *
 * `sync_state` holds what the last catalogue sync of each country did
 * ({CC: {at, dry_run, report}}). Only the synchroniser writes it, through
 * forceFill, so it is deliberately not fillable.
 *
 * Money is cast `decimal:8` (and the percentage `decimal:4`) so it reads back
 * as an exact string at the column's own scale, never as a float.
 */
class BillPaymentProvider extends Model
{
    /** @use HasFactory<BillPaymentProviderFactory> */
    use HasFactory;

    protected $fillable = [
        'plugin_id',
        'code',
        'name',
        'driver',
        'logo',
        'description',
        'status',
        'is_system',
        'fee_percent',
        'fee_fixed',
        'fee_currency',
        'processing_note',
        'config',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'status'      => 'boolean',
            'is_system'   => 'boolean',
            'fee_percent' => 'decimal:4',
            'fee_fixed'   => 'decimal:8',
            'config'      => 'array',
            'sync_state'  => 'array',
            'sort_order'  => 'integer',
            // BP1: the circuit breaker and the last balance read. Written
            // through forceFill by the breaker and the connection test only.
            'paused_at'          => 'datetime',
            'pause_reason'       => ProviderFault::class,
            'balance_amount'     => 'decimal:8',
            'balance_checked_at' => 'datetime',
        ];
    }

    /**
     * BP1: whether the breaker has stopped new payments for this provider's
     * billers. Payments already handed to it are not affected.
     */
    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }

    /**
     * Trip the breaker, once. True when this call paused it - the moment to
     * tell the admins - and false when it was already paused.
     */
    public function pauseFor(ProviderFault $fault, ?string $message): bool
    {
        $paused = static::query()
            ->whereKey($this->getKey())
            ->whereNull('paused_at')
            ->update([
                'paused_at'     => now(),
                'pause_reason'  => $fault->value,
                'pause_message' => $message === null ? null : mb_substr($message, 0, 255),
                'updated_at'    => now(),
            ]);

        $this->refresh();

        return $paused === 1;
    }

    public function resume(): void
    {
        $this->forceFill(['paused_at' => null, 'pause_reason' => null, 'pause_message' => null])->save();
    }

    public function plugin(): BelongsTo
    {
        return $this->belongsTo(Plugin::class, 'plugin_id');
    }

    public function billers(): HasMany
    {
        return $this->hasMany(Biller::class, 'bill_payment_provider_id');
    }

    /**
     * Every payment pinned to this provider. A biller can move to another
     * provider, but its past payments stay with the one that took them.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(BillPayment::class, 'bill_payment_provider_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), true);
    }

    /** A provider that ships with the module and cannot be deleted. */
    public function isSystem(): bool
    {
        return (bool) $this->is_system;
    }

    /**
     * Whether this provider's billers can only come from its catalogue sync:
     * its driver syncs billers, and it pays a biller by the provider code
     * the sync stores, which no admin form sets. A biller made by hand under
     * it has no code, so every payment to it would be refused and refunded.
     * Read from the driver class alone; nothing is built or called.
     */
    public function billersComeFromSync(): bool
    {
        $class = ((array) config('bill_payments.drivers', []))[(string) $this->driver] ?? null;

        return is_string($class) && class_exists($class) && is_subclass_of($class, SyncsBillers::class);
    }

    /**
     * The provider's own fee rule for display, e.g. "100 XOF + 1.5%".
     *
     * The fixed part names its currency because it only ever applies to
     * billers priced in that currency.
     */
    public function effectiveFeeDescription(): string
    {
        return Money::describeFee($this->fee_fixed, $this->fee_percent, (string) ($this->fee_currency ?? ''));
    }
}
