<?php

namespace App\Models\Observability;

use App\Enums\Observability\ErrorLevel;
use App\Enums\Observability\ErrorSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Five-minute rollups. The only thing the Overview charts are allowed to read.
 *
 * A sparkline drawn straight from error_events is a full scan over the largest
 * table in the module, run once per row on the list. Pre-aggregating turns
 * that into a bounded read no matter how big the install gets.
 */
class ErrorStatBucket extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'bucket_at',
        'error_group_id',
        'source',
        'level',
        'count',
    ];

    protected function casts(): array
    {
        return [
            'bucket_at' => 'datetime',
            'level'     => ErrorLevel::class,
            'source'    => ErrorSource::class,
            'count'     => 'integer',
        ];
    }
}
