<?php

namespace App\Models;

use App\Enums\KycStatus;
use App\Enums\KycVerificationMethod;
use Database\Factories\KycSubmissionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class KycSubmission extends Model
{
    /** @use HasFactory<KycSubmissionFactory> */
    use HasFactory;

    protected $fillable = [
        'kyc_template_id',
        'user_id',
        'submission_data',
        'status',
        'notes',
        'verification_method',
        'provider',
        'provider_reference',
        'provider_session_url',
        'provider_session_expires_at',
        'risk_score',
        'auto_result',
        'verified_at',
        'consent_accepted_at',
        'consent_ip',
        'consent_text',
    ];

    protected $casts = [
        'submission_data'             => 'array',
        'status'                      => KycStatus::class,
        'verification_method'         => KycVerificationMethod::class,
        'auto_result'                 => 'array',
        'risk_score'                  => 'integer',
        'verified_at'                 => 'datetime',
        'consent_accepted_at'         => 'datetime',
        'provider_session_expires_at' => 'datetime',
    ];

    /**
     * Whether the applicant agreed to their documents being sent to the
     * third-party verification provider. No consent, no provider call.
     */
    public function hasVerificationConsent(): bool
    {
        return $this->consent_accepted_at !== null;
    }

    /**
     * Whether the applicant can still be sent back into the provider's own
     * capture flow — they opened one and walked away before finishing it.
     */
    public function hasLiveHostedSession(): bool
    {
        return filled($this->provider_session_url)
            && ($this->provider_session_expires_at === null || $this->provider_session_expires_at->isFuture());
    }

    public function scopeFilter(Builder $query, Request $request): Builder
    {
        return $query
            ->when($request->filled('daterange'), function ($q) use ($request) {
                $dates = explode(',', $request->daterange);

                if (count($dates) === 2) {
                    $startDate = Carbon::parse(trim($dates[0]))->startOfDay();
                    $endDate   = Carbon::parse(trim($dates[1]))->endOfDay();

                    $q->whereBetween('created_at', [$startDate, $endDate]);
                }
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->search;

                $q->where(function ($sub) use ($search) {
                    $sub->where('notes', 'like', "%$search%")
                        ->orWhereJsonContains('submission_data', $search)
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('username', 'like', "%$search%")
                                ->orWhere('first_name', 'like', "%$search%")
                                ->orWhere('last_name', 'like', "%$search%")
                                ->orWhere('email', 'like', "%$search%");
                        })
                        /*
                         * The reference and the reviewer, because the records
                         * screen offers both in its placeholder and neither
                         * was ever matched — a compliance officer searching a
                         * submission id got an empty ledger.
                         */
                        ->orWhereHas('reviewLogs.admin', function ($adminQuery) use ($search) {
                            $adminQuery->where('name', 'like', "%$search%")
                                ->orWhere('email', 'like', "%$search%");
                        });

                    if (ctype_digit((string) $search)) {
                        $sub->orWhere('id', (int) $search);
                    }
                });
            });
    }

    public function kycTemplate()
    {
        return $this->belongsTo(KycTemplate::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewLogs(): HasMany
    {
        return $this->hasMany(KycReviewLog::class)->latest();
    }
}
