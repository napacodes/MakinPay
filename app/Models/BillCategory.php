<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BillCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group customers browse billers by. `icon` is a key from
 * config('bill_payments.category_icons'), rendered through `<x-icon>`.
 */
class BillCategory extends Model
{
    /** @use HasFactory<BillCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'icon',
        'description',
        'is_system',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_system'  => 'boolean',
            'status'     => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function billers(): HasMany
    {
        return $this->hasMany(Biller::class, 'bill_category_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), true);
    }
}
