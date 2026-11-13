<?php

namespace App\Support;

use App\Models\Admin;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Which administrators currently hold a session, and how to end one.
 *
 * Laravel's database session handler stamps `user_id` from the *default*
 * guard, which is `web` — an administrator's row therefore has a null
 * `user_id` and cannot be found by it. The guard does, however, write its
 * own key into the session payload, and that key is what identifies the
 * administrator here.
 *
 * On any other session driver there is nothing to read: the count is 0 and
 * revoking falls back to rotating the remember token, which is the part that
 * survives a closed browser.
 */
final class AdminSessionRegistry
{
    public static function driverIsDatabase(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * Live session counts keyed by admin id.
     *
     * Read once for the whole page rather than per row — a per-row query would
     * scan every session as many times as the page has members.
     *
     * @return array<int, int>
     */
    public static function countsByAdmin(): array
    {
        if (! self::driverIsDatabase()) {
            return [];
        }

        $counts = [];

        foreach (self::liveRows() as $row) {
            $adminId = self::adminIdFromPayload($row->payload);

            if ($adminId !== null) {
                $counts[$adminId] = ($counts[$adminId] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * End every session this administrator holds, and rotate the remember
     * token so a closed browser cannot walk back in.
     *
     * @return int sessions deleted
     */
    public static function revoke(Admin $admin): int
    {
        $admin->forceFill(['remember_token' => Str::random(60)])->save();

        if (! self::driverIsDatabase()) {
            return 0;
        }

        $ids = [];

        foreach (self::liveRows() as $row) {
            if (self::adminIdFromPayload($row->payload) === $admin->id) {
                $ids[] = $row->id;
            }
        }

        if ($ids === []) {
            return 0;
        }

        return DB::table(config('session.table', 'sessions'))->whereIn('id', $ids)->delete();
    }

    /**
     * @return Collection<int, object>
     */
    private static function liveRows(): Collection
    {
        $lifetimeSeconds = (int) config('session.lifetime', 120) * 60;

        try {
            return DB::table(config('session.table', 'sessions'))
                ->where('last_activity', '>=', now()->getTimestamp() - $lifetimeSeconds)
                ->get(['id', 'payload']);
        } catch (Throwable) {
            /* No sessions table on this installation — nothing to report. */
            return collect();
        }
    }

    private static function adminIdFromPayload(?string $payload): ?int
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        $decoded = base64_decode($payload, true);

        if ($decoded === false) {
            return null;
        }

        /* Session payloads are data. Never let one name a class to build. */
        $data = @unserialize($decoded, ['allowed_classes' => false]);

        if (! is_array($data)) {
            return null;
        }

        $value = $data[self::guardSessionKey()] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private static function guardSessionKey(): string
    {
        return 'login_admin_'.sha1(SessionGuard::class);
    }
}
