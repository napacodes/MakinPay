<?php

namespace App\Models;

use App\Notifications\Admin\ResetPasswordNotify;
use App\Traits\Models\Concerns\HasNotificationPreferences;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Permission\Traits\HasRoles;

class Admin extends Authenticatable
{
    use HasFactory;
    use HasNotificationPreferences;
    use HasRoles;
    use Notifiable;

    protected string $guard_name = 'admin';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'avatar',
        'name',
        'email',
        'google2fa_secret',
        'two_factor_enabled',
        'password',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function getAvatarAltAttribute(): string
    {
        return $this->avatar ?? '/general/static/default/admin.png';
    }

    /**
     * The default framework notification links to the frontend
     * `password.reset` route, which does not exist for the admin guard —
     * the admin reset URL lives behind the configurable admin prefix.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotify($token));
    }

    public function getRecentNotifications(): Collection
    {
        if (! $this->notificationDeliveryEnabled()) {
            return collect();
        }

        return $this->unreadNotifications()->latest()->get();
    }

    /**
     * The header panel's feed: the latest few, read or not.
     *
     * The panel used to list only what was unread, so reading a row made it
     * vanish and the list emptied itself as the operator worked through it.
     * Showing the recent ones whatever their state lets a read row stay put,
     * quieter, and lets "Unread" mean something as a filter. The unread count
     * on the bell still comes from notificationSignature().
     */
    public function recentNotificationFeed(int $limit = 10): Collection
    {
        if (! $this->notificationDeliveryEnabled()) {
            return collect();
        }

        return $this->notifications()->latest()->limit($limit)->get();
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

    /**
     * Get the attributes that should be cast.
     *
     * This method defines the attributes that should be cast to a specific type.
     *
     * @return array Returns an array of attribute names and their corresponding types.
     */
    protected function casts(): array
    {
        // Define the attributes that should be cast
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'status'            => 'boolean',
            'dismissed_notices' => 'array',
        ];
    }

    /**
     * Arrivals — this administrator's successful sign-ins.
     */
    public function loginActivities(): HasMany
    {
        return $this->hasMany(LoginActivity::class)->where('succeeded', true);
    }

    /**
     * Last seen. A refused attempt is not a sighting.
     *
     * The constraint goes inside `ofMany` rather than being chained after it:
     * chained, it filters the joined row but not the subquery that picks which
     * row to join, so an account whose newest attempt was refused would
     * resolve to nothing at all and read as never having signed in. The User
     * model carries the same note for the same reason.
     */
    public function latestLoginActivity(): HasOne
    {
        return $this->hasOne(LoginActivity::class)->ofMany(
            ['id' => 'max'],
            fn (Builder $query): Builder => $query->where('succeeded', true),
        );
    }

    /**
     * Present within the last five minutes.
     *
     * A sign-in is the only arrival this application records, so this answers
     * "has been here recently", not "has a tab open". The dot on the avatar
     * means exactly that.
     */
    public function isOnline(): bool
    {
        $lastActive = $this->relationLoaded('latestLoginActivity')
            ? $this->latestLoginActivity?->login_at
            : $this->loginActivities()->max('login_at');

        if ($lastActive === null) {
            return false;
        }

        return Carbon::parse($lastActive)->greaterThanOrEqualTo(now()->subMinutes(5));
    }

    /**
     * Has the admin permanently dismissed the given dashboard notice?
     */
    public function hasDismissedNotice(string $key): bool
    {
        return in_array($key, (array) ($this->dismissed_notices ?? []), true);
    }

    /**
     * Mark a dashboard notice as permanently dismissed for this admin.
     */
    public function dismissNotice(string $key): void
    {
        $current = (array) ($this->dismissed_notices ?? []);
        if (in_array($key, $current, true)) {
            return;
        }
        $current[]               = $key;
        $this->dismissed_notices = array_values(array_unique($current));
        $this->save();
    }
}
