<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class MarketplaceSettingRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'admin_id',
        'action',
        'previous_commission_user_id',
        'new_commission_user_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'admin_id'                    => 'integer',
            'previous_commission_user_id' => 'integer',
            'new_commission_user_id'      => 'integer',
            'metadata'                    => 'array',
            'created_at'                  => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Marketplace setting revisions are append-only.'));
        static::deleting(fn () => throw new LogicException('Marketplace setting revisions cannot be deleted.'));
    }
}
