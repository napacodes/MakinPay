<?php

namespace App\Models;

use App\Enums\AgentStatus;
use App\Services\QRCodeService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class Agent extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'agent_code',
        'qr_token',
        'qr_enabled',
        'qr_token_rotated_at',
        'currency_id',
        'agent_name',
        'logo',
        'description',
        'commission',
        'status',
        'rejection_reason',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        // A percentage, stored as decimal(8,4) like every other fee rate.
        'commission'          => 'decimal:4',
        'qr_enabled'          => 'boolean',
        'qr_token_rotated_at' => 'datetime',
        'status'              => AgentStatus::class,
    ];

    /**
     * Determine if the agent is approved and active.
     */
    public function isApproved(): bool
    {
        return $this->status === AgentStatus::APPROVED;
    }

    /**
     * Actions are locked when agent is disabled or rejected.
     */
    public function isActionLocked(): bool
    {
        return in_array($this->status, [AgentStatus::DISABLED, AgentStatus::REJECTED], true);
    }

    public function scopeActive($query)
    {
        return $query->where('status', AgentStatus::APPROVED);
    }

    /**
     * Filter scope for backend listings (mirrors Merchant::scopeFilter).
     */
    public function scopeFilter($query, Request $request)
    {
        /*
         * Both of these filter on status — `type` is the older spelling, kept
         * because existing links still use it. An unrecognised value used to
         * reach the where clause unchecked, so a typo or a stale bookmark
         * returned an empty table that read as "no agents" rather than "no
         * such status" — and the tab strip, which falls back to All for a
         * value it does not recognise, disagreed with the rows below it.
         */
        $statuses = AgentStatus::all();

        $query->when(
            $request->filled('type') && in_array($request->type, $statuses, true),
            fn ($q) => $q->where('status', $request->type)
        );

        $query->when(
            $request->filled('status') && in_array($request->status, $statuses, true),
            fn ($q) => $q->where('status', $request->status)
        );

        $query->when($request->filled('daterange'), function ($q) use ($request) {
            $dateRange = explode(',', $request->daterange);

            if (count($dateRange) === 2) {
                [$startDate, $endDate] = $dateRange;

                $q->whereBetween('created_at', [
                    Carbon::parse(trim($startDate))->startOfDay(),
                    Carbon::parse(trim($endDate))->endOfDay(),
                ]);
            }
        });

        $query->when($request->filled('currency') && $request->currency !== 'all', function ($q) use ($request) {
            $code = $request->currency;

            $q->where(function ($q) use ($code) {
                $q->whereHas('supportedCurrencies', fn ($c) => $c->where('currencies.code', $code))
                    ->orWhereHas('currency', fn ($c) => $c->where('code', $code));
            });
        });

        $query->when($request->filled('search'), function ($q) use ($request) {
            $search = $request->search;
            $q->where(function ($q) use ($search) {
                $q->where('agent_name', 'like', "%{$search}%")
                    ->orWhere('agent_code', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($u) use ($search) {
                        $u->where('email', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('username', 'like', "%{$search}%");
                    });
            });
        });

        return $query;
    }

    public function getLogoAttribute(?string $value): string
    {
        return $value ?? '/general/static/default/shop.png';
    }

    /**
     * Get the user that owns the agent.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the currency associated with the agent.
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function supportedCurrencies(): BelongsToMany
    {
        return $this->belongsToMany(Currency::class, 'agent_currencies')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    /**
     * @return array<int, int>
     */
    public function supportedCurrencyIds(): array
    {
        $ids = $this->relationLoaded('supportedCurrencies')
            ? $this->supportedCurrencies->pluck('id')
            : $this->supportedCurrencies()->pluck('currencies.id');

        if ($ids->isEmpty()
            && $this->currency_id !== null
            && (! $this->relationLoaded('currency') || (bool) $this->currency?->status)) {
            $ids = collect([(int) $this->currency_id]);
        }

        return $ids
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function supportsCurrency(int $currencyId): bool
    {
        return in_array($currencyId, $this->supportedCurrencyIds(), true);
    }

    public function qrCashOutUrl(): string
    {
        return route('user.agent.qr.cash-out', ['token' => $this->qr_token]);
    }

    public function qrCashOutSvg(int $size = 260): string
    {
        return app(QRCodeService::class)->generate($this->qrCashOutUrl(), $size);
    }

    public function regenerateQrToken(): void
    {
        do {
            $token = 'aqr_'.Str::lower(Str::random(32));
        } while (self::query()->withTrashed()->where('qr_token', $token)->whereKeyNot($this->id)->exists());

        $this->forceFill([
            'qr_token'            => $token,
            'qr_token_rotated_at' => now(),
        ])->save();
    }

    public function operations(): HasMany
    {
        return $this->hasMany(AgentOperation::class);
    }

    public function commissionRules(): BelongsToMany
    {
        return $this->belongsToMany(AgentCommissionRule::class, 'agent_commission_rule_assignments')
            ->withPivot(['operation_type', 'priority', 'status'])
            ->withTimestamps();
    }

    public function commissionRuleAssignments(): HasMany
    {
        return $this->hasMany(AgentCommissionRuleAssignment::class);
    }
}
