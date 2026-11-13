<?php

namespace App\Models;

use App\Enums\LoginMethod;
use App\Enums\LoginRisk;
use App\Enums\UserRole;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class LoginActivity extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'admin_id',
        'succeeded',
        'ip_address',
        'country',
        'city',
        'isp',
        'latitude',
        'longitude',
        'device',
        'device_type',
        'method',
        'risk',
        'risk_reason',
        'session_id',
        'failed_attempts_before',
        'session_revoked_at',
        'browser',
        'platform',
        'user_agent',
        'login_at',
    ];

    protected $casts = [
        'succeeded'              => 'boolean',
        'login_at'               => 'datetime',
        'session_revoked_at'     => 'datetime',
        'acknowledged_at'        => 'datetime',
        'risk'                   => LoginRisk::class,
        'method'                 => LoginMethod::class,
        'latitude'               => 'float',
        'longitude'              => 'float',
        'failed_attempts_before' => 'integer',
    ];

    /**
     * Arrivals — the rows this table held before it also recorded refusals.
     *
     * Every screen that shows someone where they have signed in from means
     * this scope, which is why the User relationships apply it themselves
     * rather than leaving each caller to remember.
     */
    public function scopeSucceeded(Builder $query): Builder
    {
        return $query->where('succeeded', true);
    }

    /**
     * Refused attempts — a wrong password against a real account.
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('succeeded', false);
    }

    /**
     * Customer arrivals only.
     *
     * The table also records administrators now, and every screen that existed
     * before that means customers. This scope is how a caller says so out
     * loud instead of relying on a join to filter the others out.
     */
    public function scopeForUsers(Builder $query): Builder
    {
        return $query->whereNotNull('user_id');
    }

    public function scopeForAdmins(Builder $query): Builder
    {
        return $query->whereNotNull('admin_id');
    }

    /**
     * Everything the risk evaluator did not call routine.
     *
     * Rows written before the evaluator existed carry the `normal` default,
     * so they stay out of this scope rather than arriving as a backlog of
     * alerts about sign-ins nobody measured.
     */
    public function scopeFlagged(Builder $query): Builder
    {
        return $query->whereIn('risk', LoginRisk::flagged());
    }

    public function scopeRisk(Builder $query, LoginRisk|string $risk): Builder
    {
        return $query->where('risk', $risk instanceof LoginRisk ? $risk->value : $risk);
    }

    /**
     * The city and country as one line, for a screen that shows them together.
     */
    public function location(): string
    {
        return implode(', ', array_filter([$this->city, $this->country])) ?: __('Unknown');
    }

    /**
     * Whether the session this row opened can still be signed out.
     */
    public function sessionIsLive(): bool
    {
        return $this->succeeded
            && $this->session_revoked_at === null
            && $this->risk !== LoginRisk::BLOCKED;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * Scope to filter login activities.
     */
    public function scopeFilter($query, Request $request)
    {
        // Filter by Date Range
        if ($request->filled('daterange')) {
            $dates = explode(',', $request->daterange);
            if (count($dates) === 2) {
                [$startDate, $endDate] = $dates;
                $query->whereBetween('login_at', [
                    Carbon::parse($startDate)->startOfDay(),
                    Carbon::parse($endDate)->endOfDay(),
                ]);
            }
        }

        /*
         * Risk. "flagged" is the screen's word for everything above normal,
         * so it is accepted alongside the individual cases rather than making
         * the toolbar send three values to mean one button.
         */
        if ($request->filled('risk')) {
            if ($request->risk === 'flagged') {
                $query->flagged();
            } elseif (in_array($request->risk, LoginRisk::all(), true)) {
                $query->risk($request->risk);
            }
        }

        // Filter by User Role
        if ($request->filled('type') && in_array($request->type, UserRole::all())) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('role', $request->type);
            });
        }

        // Filter by Search Term (IP, User, Email, Full Name, Country, Device, Browser, Platform)
        if ($request->filled('search')) {
            $searchTerm = $request->search;

            $query->where(function ($q) use ($searchTerm) {
                $q->where('ip_address', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('country', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('city', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('isp', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('device', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('browser', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('platform', 'LIKE', "%{$searchTerm}%")
                    ->orWhereHas('user', function ($subQuery) use ($searchTerm) {
                        $subQuery->where('username', 'LIKE', "%{$searchTerm}%")
                            ->orWhere('email', 'LIKE', "%{$searchTerm}%")
                            ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$searchTerm}%"]);
                    });
            });
        }

        return $query;
    }
}
