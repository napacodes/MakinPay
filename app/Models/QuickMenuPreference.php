<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's personal quick-menu selection.
 *
 * `items` holds the ordered list of catalog keys (route names) the user
 * pinned. A missing row - or an empty list - means "show everything",
 * which is the behaviour the panel had before it became editable.
 */
class QuickMenuPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'items',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'items' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
