<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Marketplace\VendorStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vendor extends Model
{
    use HasFactory;

    /**
     * Buyer-facing business categories, keyed by the stored value.
     *
     * @var array<string, string>
     */
    public const CATEGORIES = [
        'shops'          => 'Shops',
        'internet_bills' => 'Internet & Bills',
        'services'       => 'Services',
        'freelancers'    => 'Freelancers',
        'others'         => 'Others',
    ];

    protected $fillable = [
        'user_id',
        'display_name',
        'status',
        'commission_pct_override',
        'about',
        'category',
        'location',
        'stripe_connect_account_id',
        'stripe_onboarding_status',
        'approved_at',
        'suspended_at',
        'suspend_reason',
    ];

    protected function casts(): array
    {
        return [
            'status'                  => VendorStatus::class,
            'commission_pct_override' => 'decimal:4',
            'approved_at'             => 'datetime',
            'suspended_at'            => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(MarketplaceOrder::class);
    }

    public function isActive(): bool
    {
        return $this->status === VendorStatus::ACTIVE;
    }

    public function categoryLabel(): string
    {
        return __(self::CATEGORIES[$this->category] ?? 'Others');
    }

    /**
     * Two-letter monogram used by the avatar tiles.
     */
    public function initials(): string
    {
        $initials = collect(explode(' ', (string) $this->display_name))
            ->filter()
            ->take(2)
            ->map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)))
            ->implode('');

        return $initials !== '' ? $initials : 'B';
    }

    /**
     * Deterministic avatar colour variant (1-4) derived from the business
     * name, so the same business always renders the same tile colour.
     */
    public function avatarVariant(): int
    {
        return (crc32((string) $this->display_name) % 4) + 1;
    }
}
