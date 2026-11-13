<?php

namespace App\Models;

use Database\Factories\KycTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KycTemplate extends Model
{
    /** @use HasFactory<KycTemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'fields',
        'applicable_to',
        'routing',
        'status',
        'updated_by',
    ];

    protected $casts = [
        'fields'        => 'array',
        'applicable_to' => 'array',
        'status'        => 'boolean',
    ];

    /**
     * Scope a query to only include active templates.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }

    /**
     * Whether this template insists on a human, whatever the providers cover.
     *
     * Coverage is decided per submission from the applicant's country, so a
     * template could never say "no provider handles this" — but an admin can
     * say "do not send this form to one at all", which is what enhanced due
     * diligence means. Null and 'auto' both read as today's behaviour, so an
     * installation upgraded before anyone opens the builder is unchanged.
     */
    public function isManualOnly(): bool
    {
        return $this->routing === 'manual';
    }

    /**
     * @return HasMany<KycSubmission>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(KycSubmission::class);
    }

    /**
     * The administrator who last saved this template.
     *
     * Nullable, and null on every row that existed before the column did —
     * the list shows the date alone for those rather than inventing an author.
     *
     * @return BelongsTo<Admin, KycTemplate>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by');
    }
}
