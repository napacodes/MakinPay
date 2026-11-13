<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AddonStatus;
use Database\Factories\AddonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An installed add-on. Its code and manifest live in addons/<slug>; see
 * App\Services\Addons\AddonRegistry.
 *
 * @property int                       $id
 * @property string                    $slug
 * @property string                    $name
 * @property string                    $version
 * @property AddonStatus               $status
 * @property string|null               $last_error
 * @property array<string, mixed>|null $meta
 * @property Carbon|null               $installed_at
 * @property Carbon|null               $enabled_at
 */
class Addon extends Model
{
    /** @use HasFactory<AddonFactory> */
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'version',
        'status',
        'last_error',
        'meta',
        'installed_at',
        'enabled_at',
    ];

    protected function casts(): array
    {
        return [
            'status'       => AddonStatus::class,
            'meta'         => 'array',
            'installed_at' => 'datetime',
            'enabled_at'   => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === AddonStatus::ACTIVE;
    }
}
