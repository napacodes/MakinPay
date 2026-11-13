<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where to wake one customer's phone.
 *
 * One row per install, not per session: see the migration for why this is not
 * a column on personal_access_tokens.
 *
 * @property int         $user_id
 * @property string|null $device_id
 * @property string      $token
 */
class UserPushToken extends Model
{
    use HasFactory;

    protected $table = 'user_push_tokens';

    protected $fillable = [
        'user_id',
        'device_id',
        'token',
        'platform',
        'app_version',
        'last_registered_at',
    ];

    protected function casts(): array
    {
        return [
            'last_registered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, UserPushToken>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Register or refresh a device's token.
     *
     * Keyed on the device rather than on the token, so a handset whose token
     * the operating system rotated updates its row instead of leaving a dead
     * one behind that every later notification still tries to reach.
     *
     * The same token arriving under a different account is moved, not copied:
     * a shared handset that a second customer signs into must not keep
     * delivering the first customer's notifications.
     */
    public static function register(User $user, string $token, array $device = []): self
    {
        $deviceId = $device['id'] ?? null;

        static::query()
            ->where('token', $token)
            ->where(function ($query) use ($user, $deviceId): void {
                $query->where('user_id', '!=', $user->id)
                    ->orWhere(fn ($inner) => $inner->where('user_id', $user->id)->where('device_id', '!=', $deviceId));
            })
            ->delete();

        return static::query()->updateOrCreate(
            ['user_id' => $user->id, 'device_id' => $deviceId],
            [
                'token'              => $token,
                'platform'           => $device['platform']    ?? null,
                'app_version'        => $device['app_version'] ?? null,
                'last_registered_at' => now(),
            ],
        );
    }
}
