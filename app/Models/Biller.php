<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillPayment\BillerAmountType;
use Database\Factories\BillerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One company a customer can pay.
 *
 * Every amount on the row (min, max, the fixed-fee override and each item's
 * price) is in the biller's own `currency`. A null fee column inherits the
 * provider's rule. Money is cast `decimal:8` so it reads back as an exact
 * string, never as a float.
 */
class Biller extends Model
{
    /** @use HasFactory<BillerFactory> */
    use HasFactory;

    protected $fillable = [
        'bill_category_id',
        'bill_payment_provider_id',
        'name',
        'country',
        'currency',
        'logo',
        'description',
        'customer_number_label',
        'customer_number_example',
        'customer_number_help',
        'customer_number_pattern',
        'amount_type',
        'min_amount',
        'max_amount',
        'fee_percent',
        'fee_fixed',
        'token_label',
        'processing_note',
        'instructions',
        'external_code',
        'external_meta',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'amount_type'   => BillerAmountType::class,
            'min_amount'    => 'decimal:8',
            'max_amount'    => 'decimal:8',
            'fee_percent'   => 'decimal:4',
            'fee_fixed'     => 'decimal:8',
            'external_meta' => 'array',
            'status'        => 'boolean',
            'sort_order'    => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BillCategory::class, 'bill_category_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(BillPaymentProvider::class, 'bill_payment_provider_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillerItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function activeItems(): HasMany
    {
        return $this->items()->where('status', true);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillPayment::class);
    }

    /**
     * Whether the catalogue sync manages this biller's packages, amount type
     * and external meta (it was imported, or matched, by a sync).
     */
    public function isSyncManaged(): bool
    {
        $meta = is_array($this->external_meta) ? $this->external_meta : [];

        return ($meta['source'] ?? null) === 'sync';
    }

    /**
     * Billers a customer may pay right now: the biller, its category and its
     * provider are all switched on. Switching any one of them off hides the
     * biller without touching the others.
     */
    public function scopePayable(Builder $query): Builder
    {
        return $query
            ->where($this->qualifyColumn('status'), true)
            ->whereHas('category', fn (Builder $category): Builder => $category->where('status', true))
            ->whereHas('provider', fn (Builder $provider): Builder => $provider->where('status', true));
    }
}
