<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\KycStatus;
use App\Enums\SystemAccountType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\P2P\PaymentAccount;
use App\Services\CurrencyService;
use App\Services\WalletService;
use App\Support\HomeCountry;
use App\Traits\Models\Concerns\HasNotificationPreferences;
use App\Traits\Models\Concerns\HasWebPushSubscriptions;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\HasApiTokens;
use LogicException;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;
    use HasFactory;
    use HasNotificationPreferences;
    use HasWebPushSubscriptions;
    use Notifiable;

    /**
     * Minutes since `last_seen_at` within which a user is considered online.
     * Paired with the TrackUserPresence middleware's 1-minute write throttle.
     */
    public const ONLINE_WITHIN_MINUTES = 3;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'referral_code',
        'rank_id',
        'old_ranks',
        'avatar',
        'first_name',
        'last_name',
        'business_name',
        'business_address',
        'username',
        'gender',
        'birthday',
        'email',
        'phone',
        'phone_verified_at',
        'phone_verification_enabled',
        'state',
        'city',
        'postal_code',
        'country',
        'display_currency',
        'monthly_statement_email',
        'address',
        'role',
        'google2fa_secret',
        'two_factor_enabled',
        'status',
        'suspended_at',
        'suspended_until',
        'suspension_reason',
        'suspension_note',
        'must_change_password',
        'password',
        'wallet_pin',
        'email_verified_at',
        'signup_bonus_awarded_at',
        'signup_bonus_seen_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'wallet_pin',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     * (If you're using Laravel older approach, keep $casts in the property.)
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_ranks'           => 'array',
            'role'                => UserRole::class,
            'system_account_type' => SystemAccountType::class,
            'gender'              => Gender::class,
            'status'              => UserStatus::class,
            'email_verified_at'   => 'datetime',
            // PH7: when an admin corrected the registered country.
            'country_corrected_at'       => 'datetime',
            'password_changed_at'        => 'datetime',
            'last_seen_at'               => 'datetime',
            'phone_verified_at'          => 'datetime',
            'signup_bonus_awarded_at'    => 'datetime',
            'signup_bonus_seen_at'       => 'datetime',
            'phone_verification_enabled' => 'boolean',
            'suspended_at'               => 'datetime',
            'suspended_until'            => 'datetime',
            'must_change_password'       => 'boolean',
            'password'                   => 'hashed',
            'wallet_pin'                 => 'hashed',
            'p2p_trading_suspended_at'   => 'datetime',
        ];
    }

    /**
     * Determine whether the user has configured a wallet PIN.
     */
    public function hasWalletPin(): bool
    {
        return ! empty($this->wallet_pin);
    }

    public function hasVerifiedPhone(): bool
    {
        return ! empty($this->phone) && $this->phone_verified_at !== null;
    }

    /**
     * Whether this account's registered country is the site's home country.
     *
     * Decided by users.country, never by the phone number: a customer from
     * the home country can hold a foreign SIM, and under a +1 home a Canadian
     * shares the dial code. False when no home country is set.
     */
    public function isLocalUser(): bool
    {
        return HomeCountry::includes($this->country);
    }

    /**
     * PH10: which side of the home country this account is on, as tax and
     * the admin read it: 'local', 'international', or null when it cannot be
     * said (no home country set, or a country the list does not know).
     * Exposed as `account_scope`; it changes nothing by itself.
     */
    public function accountScope(): ?string
    {
        if ($this->isLocalUser()) {
            return 'local';
        }

        return $this->isInternationalUser() ? 'international' : null;
    }

    /**
     * PH10: the accounts on one side of the home country, by the same rule
     * as accountScope(): local is the home country, international is any
     * other country the list knows. Nothing when there is no home country,
     * since nobody is either then.
     */
    public function scopeAccountScope(Builder $query, string $scope): Builder
    {
        $home = HomeCountry::code();

        if ($home === null || ! in_array($scope, ['local', 'international'], true)) {
            return $query;
        }

        $codes = $scope === 'local'
            ? [$home]
            : array_values(array_diff(HomeCountry::selectableCodes(), [$home]));

        // Stored as sign-up stored it, so both cases are matched.
        return $query->whereIn($this->qualifyColumn('country'), array_merge($codes, array_map('strtolower', $codes)));
    }

    /**
     * PH7: the admin who corrected this account's registered country, when
     * one did. The audit note on the account, with country_corrected_from
     * and country_corrected_at.
     *
     * @return BelongsTo<Admin, $this>
     */
    public function countryCorrectedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'country_corrected_by');
    }

    /**
     * Whether this account's registered country is known and is not the home
     * country.
     *
     * NEVER compute "international" as ! isLocalUser(): with no home country
     * set, or an account with no country, nobody is either - the answer is
     * unknown, and a levy on international customers must not land on all of
     * them.
     */
    public function isInternationalUser(): bool
    {
        return HomeCountry::isForeign($this->country);
    }

    public function hasEnabledPhoneVerification(): bool
    {
        return $this->hasVerifiedPhone() && (bool) $this->phone_verification_enabled;
    }

    public function routeNotificationForTwilio()
    {
        return $this->phone;
    }

    public function isP2pTradingSuspended(): bool
    {
        return $this->getAttribute('p2p_trading_suspended_at') !== null;
    }

    /**
     * Whether the user has been active within the online presence window.
     * Reads only the already-loaded `last_seen_at` column, so it is safe to
     * call per row on eager-loaded relations without triggering queries.
     */
    public function isOnline(): bool
    {
        $lastSeenAt = $this->last_seen_at;

        return $lastSeenAt !== null
            && $lastSeenAt->greaterThanOrEqualTo(now()->subMinutes(self::ONLINE_WITHIN_MINUTES));
    }

    /**
     * One of the shared sandbox logins published with the demo install.
     *
     * True only while `APP_DEMO=true` - on a customer's own installation
     * these are ordinary accounts with no special treatment at all.
     */
    public function isDemoProtected(): bool
    {
        return isDemoProtectedAccount($this->getAttribute('email'));
    }

    /**
     * An admin-imposed suspension that is still in force.
     *
     * A suspension is a window rather than a flag: `suspended_at` marks the
     * start and `suspended_until` the automatic end (null = until an admin
     * lifts it). Reading the clock here means an expired suspension stops
     * biting the moment it lapses, whether or not the scheduled sweep that
     * clears the columns has run yet.
     */
    public function isSuspended(): bool
    {
        /*
         * A published demo login is never suspended, whatever the columns
         * say. The credentials are printed on the sign-in page for anyone
         * who visits, so "suspend this account" is a button every visitor
         * effectively has - one press and the next evaluator finds a dead
         * demo. Reading it here rather than only refusing the write also
         * frees an account some earlier build already locked.
         *
         * Abuse is answered at the address instead, by
         * {@see \App\Services\Security\IpAbuseGuard}: the person doing it
         * stops getting in, and everyone else keeps the demo.
         */
        if ($this->isDemoProtected()) {
            return false;
        }

        if ($this->getAttribute('suspended_at') === null) {
            return false;
        }

        $until = $this->getAttribute('suspended_until');

        return $until === null || $until->isFuture();
    }

    public function suspensionIsIndefinite(): bool
    {
        return $this->getAttribute('suspended_at') !== null && $this->getAttribute('suspended_until') === null;
    }

    /**
     * Clear the suspension window, keeping the reason on the row for the
     * audit trail is not this method's job: callers log before they lift.
     */
    public function liftSuspension(): void
    {
        $this->forceFill([
            'suspended_at'      => null,
            'suspended_until'   => null,
            'suspension_reason' => null,
            'suspension_note'   => null,
        ])->save();
    }

    /**
     * Rows whose suspension is currently in force.
     */
    public function scopeSuspended(Builder $query): Builder
    {
        return $query->whereNotNull('suspended_at')
            ->where(function (Builder $window): void {
                $window->whereNull('suspended_until')
                    ->orWhere('suspended_until', '>', now());
            });
    }

    public function scopeActive()
    {
        return $this->where('status', UserStatus::ACTIVE);
    }

    public function scopeFilter(Builder $query, Request $request): Builder
    {
        return $query->whereNull('system_account_type')
            ->with(['kycSubmission', 'latestLoginActivity'])
            ->when($request->filled('role') && $request->role !== 'all', function ($q) use ($request) {
                $q->where('role', $request->role);
            })
            ->when($request->filled('kyc_status') && $request->kyc_status !== 'all', function ($q) use ($request) {
                $q->whereHas('kycSubmission', function ($subQuery) use ($request) {
                    $subQuery->where('status', $request->kyc_status);
                });
            })
            ->when($request->filled('status') && $request->status !== 'all', function ($q) use ($request) {
                $q->where('status', $request->status);
            })
            ->when($request->filled('email_verified') && $request->email_verified !== 'all', function ($q) use ($request) {
                $request->email_verified == '1'
                    ? $q->whereNotNull('email_verified_at')
                    : $q->whereNull('email_verified_at');
            })
            // PH10: local or international, by the home country; ignored without one.
            ->when($request->filled('scope') && $request->scope !== 'all', function ($q) use ($request) {
                $q->accountScope((string) $request->scope);
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->search;
                $q->where(function ($subQuery) use ($search) {
                    $subQuery->where('first_name', 'like', "%$search%")
                        ->orWhere('last_name', 'like', "%$search%")
                        ->orWhere('username', 'like', "%$search%")
                        ->orWhere('phone', 'like', "%$search%")
                        ->orWhere('email', 'like', "%$search%");
                });
            });
    }

    public function scopeKycUnverified($query)
    {
        return $query->where(function ($q) {
            $q->doesntHave('kycSubmission')
                ->orWhereHas('kycSubmission', function ($sub) {
                    $sub->where('status', '!=', KycStatus::APPROVED);
                });
        });
    }

    /**
     * Accessor for avatar field: returns a default if not set.
     */
    public function getAvatarAltAttribute(): string
    {
        return $this->avatar ?? '/general/static/default/user.png';
    }

    /**
     * A convenience accessor for displaying the user's full name.
     */
    public function getNameAttribute(): string
    {
        // Assuming `title()` is a custom global helper, or you have a better approach
        return title($this->first_name.' '.$this->last_name);
    }

    // ******************* Wallets relations & methods *******************//

    public function activeWallets(?string $role = null)
    {
        return $this->wallets()->with(['currency.roles'])->active($role)->get();
    }

    /**
     * The currency this user's aggregate totals are presented in.
     *
     * Presentation only. Nothing about what is stored, charged or held moves
     * with it — a 0.5 BTC transaction reads as 0.5 BTC whatever this returns.
     * It governs the figures that have no currency of their own, such as
     * "total deposits" summed across several wallets.
     *
     * Falls back to the site base whenever the choice is unset, unknown,
     * disabled, or priced at a rate that cannot be converted through. That
     * last one matters: presenting a total through a stale or placeholder rate
     * is how a dashboard shows a number nobody can explain, so an unusable
     * preference silently reverts to the unit the ledger is already kept in
     * rather than rendering something wrong.
     */
    public function displayCurrency(): string
    {
        $base = strtoupper((string) (siteCurrency() ?: ''));
        $code = strtoupper(trim((string) ($this->display_currency ?? '')));

        if ($code === '' || $code === $base) {
            return $base;
        }

        $currency = app(CurrencyService::class)->getCurrencyByCode($code);

        if (! $currency instanceof Currency || ! $currency->status) {
            return $base;
        }

        return $currency->isConvertible() ? strtoupper((string) $currency->code) : $base;
    }

    public function wallets()
    {
        return $this->hasMany(Wallet::class);
    }

    public function p2pPaymentAccounts(): HasMany
    {
        return $this->hasMany(PaymentAccount::class);
    }

    /**
     * The user's personal quick-menu selection.
     *
     * Absent until they first edit the panel, in which case the whole
     * catalog is shown.
     */
    public function quickMenuPreference(): HasOne
    {
        return $this->hasOne(QuickMenuPreference::class);
    }

    public function vendor(): HasOne
    {
        return $this->hasOne(Vendor::class);
    }

    public function getDefaultWalletAttribute()
    {
        // Delegate to WalletService or keep your custom logic here
        return app(WalletService::class)->getDefaultWallet($this);
    }

    public function createWallet($currencyId)
    {
        return app(WalletService::class)->createWalletForCurrency($this, $currencyId);
    }

    public function availableCurrenciesForCreateWallet()
    {
        return Currency::query()
            ->active()
            ->whereNotIn('id', $this->wallets->pluck('currency_id'))
            ->get();
    }

    // ******************* Transactions & Referrals *******************//

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    public function vaults(): HasMany
    {
        return $this->hasMany(Vault::class);
    }

    public function scheduledPayments(): HasMany
    {
        return $this->hasMany(ScheduledPayment::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function savedPhoneNumbers(): HasMany
    {
        return $this->hasMany(SavedPhoneNumber::class);
    }

    public function mobileRecharges(): HasMany
    {
        return $this->hasMany(MobileRecharge::class);
    }

    public function billPayments(): HasMany
    {
        return $this->hasMany(BillPayment::class);
    }

    /**
     * "My bills": the billers and account numbers this customer kept.
     */
    public function savedBills(): HasMany
    {
        return $this->hasMany(SavedBill::class);
    }

    /**
     * Every customer read of a mass payment goes through this relation, so a
     * batch is only ever found inside its owner's scope.
     */
    public function massPaymentBatches(): HasMany
    {
        return $this->hasMany(MassPaymentBatch::class);
    }

    public function phoneVerificationCodes(): HasMany
    {
        return $this->hasMany(PhoneVerificationCode::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class);
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(UserSubscription::class)
            ->whereIn('status', ['active', 'trial', 'grace'])
            ->latest();
    }

    public function walletEarnStakes(): HasMany
    {
        return $this->hasMany(WalletEarnStake::class);
    }

    public function walletEarnRewards(): HasMany
    {
        return $this->hasMany(WalletEarnReward::class);
    }

    public function getReferralLinkAttribute(): string
    {
        return route('user.register', ['ref' => $this->referral_code]);
    }

    public function referrals()
    {
        return $this->hasMany(Referral::class, 'user_id');
    }

    public function referredBy()
    {
        return $this->hasOne(Referral::class, 'referred_user_id');
    }

    public function indirectReferrals()
    {
        return $this->hasManyThrough(
            Referral::class,
            Referral::class,
            'parent_referral_id',
            'user_id',
            'id',
            'id'
        );
    }

    // ******************* Ranking & Roles *******************//

    public function rank()
    {
        return $this->belongsTo(UserRank::class, 'rank_id');
    }

    public function isUser(): bool
    {
        return $this->role === UserRole::USER;
    }

    public function isMerchant(): bool
    {
        return $this->role === UserRole::MERCHANT;
    }

    public function isAgent(): bool
    {
        return $this->role === UserRole::AGENT;
    }

    public function isSystemAccount(?SystemAccountType $type = null): bool
    {
        if ($this->system_account_type === null) {
            return false;
        }

        return $type === null || $this->system_account_type === $type;
    }

    protected static function booted(): void
    {
        static::updating(function (self $user): void {
            if ($user->getOriginal('system_account_type') !== null) {
                throw new LogicException('System accounts are immutable.');
            }
        });

        static::deleting(function (self $user): void {
            if ($user->isSystemAccount()) {
                throw new LogicException('System accounts cannot be deleted.');
            }
        });
    }

    /**
     * Each user can have one agent profile.
     */
    public function agent(): HasOne
    {
        return $this->hasOne(Agent::class);
    }

    public function getRecentNotifications(): Collection
    {
        if (! $this->notificationDeliveryEnabled()) {
            return collect();
        }

        return $this->unreadNotifications()->latest()->get();
    }

    /**
     * Lightweight fingerprint of the unread feed, used by the notification poller
     * to decide whether the dropdown markup actually needs to be re-fetched.
     *
     * @return array{count: int, latest: string|null}
     */
    public function notificationSignature(): array
    {
        if (! $this->notificationDeliveryEnabled()) {
            return ['count' => 0, 'latest' => null];
        }

        $summary = $this->unreadNotifications()
            ->reorder()
            ->selectRaw('COUNT(*) as unread_total, MAX(created_at) as latest_created_at')
            ->first();

        return [
            'count'  => (int) ($summary->unread_total ?? 0),
            'latest' => $summary?->latest_created_at ? (string) $summary->latest_created_at : null,
        ];
    }

    // ******************* KYC & Verification *******************//

    /**
     * Each user can only have one active KYC submission.
     */
    public function kycSubmission(): HasOne
    {
        // The newest submission is the one that counts. Without an order an
        // account that ever accumulated a second row answered with whichever
        // the database returned first, so an approved user could read as
        // pending or rejected.
        return $this->hasOne(KycSubmission::class)->latestOfMany();
    }

    /**
     * Check if the user is KYC verified (approved).
     */
    public function isKycVerified(): bool
    {
        $submission = $this->kycSubmission;

        return $submission && $submission->status === KycStatus::APPROVED;
    }

    /**
     * Each user can have multiple tickets.
     */
    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    public function merchants()
    {
        return $this->hasMany(Merchant::class);
    }

    /**
     * Where this account has actually signed in from.
     *
     * Scoped to successful arrivals. login_activities also carries refused
     * attempts now, and every caller of this relationship — the security
     * screen's device list, its known-IP count, the admin's per-user history
     * — is asking about sessions the account really opened. An attacker's
     * wrong password must not turn their IP into a familiar one.
     *
     * Read refusals through the model's own `failed()` scope instead.
     */
    public function loginActivities(): User|HasMany
    {
        return $this->hasMany(LoginActivity::class)->where('succeeded', true);
    }

    /**
     * Last seen. A refused attempt is not a sighting.
     *
     * The constraint goes inside `ofMany` rather than being chained after it:
     * chained, it filters the joined row but not the subquery that picks
     * which row to join, so an account whose newest attempt was refused would
     * resolve to nothing at all and read as never having signed in.
     */
    public function latestLoginActivity(): HasOne
    {
        return $this->hasOne(LoginActivity::class)->ofMany(
            ['id' => 'max'],
            fn (Builder $query): Builder => $query->where('succeeded', true),
        );
    }

    /**
     * Define the relationship between User and UserFeature
     */
    public function features(): User|HasMany
    {
        return $this->hasMany(UserFeature::class)->orderBy('sort_order', 'asc');
    }

    /**
     * Whether this site requires its users to confirm their email address.
     *
     * Verification is an administrator's decision, not a property of the
     * code: the toggle lives in Security Settings and ships off, so an
     * upgrade changes nothing until someone turns it on.
     */
    public static function emailVerificationRequired(): bool
    {
        return (bool) setting('email_verification_required', false);
    }

    /**
     * Send nothing while verification is switched off.
     *
     * The model implements MustVerifyEmail so Laravel's Registered listener
     * finally fires — without the contract it silently skipped every
     * registration and no verification email was ever sent. But the listener
     * is unconditional once the contract exists, so the admin toggle is
     * enforced here instead: off means no email, on any path, including the
     * manual resend.
     *
     * `hasVerifiedEmail()` is deliberately left alone. It answers "has this
     * person confirmed their address", which the admin user panel and the
     * signup-bonus gate both read and both need to stay truthful.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->trySendEmailVerificationNotification();
    }

    /**
     * Send the verification email and say whether it went out.
     *
     * The contract method above is called from Laravel's Registered listener
     * in the middle of the signup request, where a mail server that is down
     * or misconfigured used to surface as a 500 after the account had already
     * been created. The failure is reported and the person is let through to
     * the verification notice, whose resend button reads this return value
     * and can tell them what happened.
     */
    public function trySendEmailVerificationNotification(): bool
    {
        if (! self::emailVerificationRequired()) {
            return false;
        }

        try {
            parent::sendEmailVerificationNotification();

            return true;
        } catch (\Throwable $e) {
            report($e);

            Log::error('Verification email could not be sent', [
                'user_id' => $this->getKey(),
                'error'   => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function hasFeature(string $feature): bool
    {
        return $this->features()->where('feature', $feature)->where('status', true)->exists();
    }

    /**
     * The phones this customer has registered for push.
     *
     * @return HasMany<UserPushToken>
     */
    public function pushTokens(): HasMany
    {
        return $this->hasMany(UserPushToken::class);
    }

    /**
     * The registration tokens a notification should be sent to.
     *
     * Laravel's routeNotificationFor convention, so the FCM channel asks the
     * notifiable where to deliver rather than querying for it - which is what
     * lets a test notifiable stand in for a user.
     *
     * @return array<int, string>
     */
    public function routeNotificationForFcm(): array
    {
        return $this->pushTokens()->pluck('token')->all();
    }

    // app/Models/User.php
    public function virtualCards()
    {
        return $this->hasMany(VirtualCard::class);
    }
}
