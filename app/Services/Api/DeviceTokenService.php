<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Mints and revokes the access tokens the mobile app signs in with.
 *
 * Two rules hold everything here together.
 *
 * Abilities are derived from the user's role and are never accepted from the
 * caller. There is no parameter to pass them in, so no controller can be
 * talked into minting a merchant token for a customer by forwarding a field
 * from the request body.
 *
 * A device holds at most one token. Re-issuing for a device id revokes what
 * that device held first, so an app that is reinstalled, or signs in again
 * after its token expired, does not leave a working credential behind on a
 * handset that was wiped and sold.
 */
final class DeviceTokenService
{
    /**
     * Granted to every signed-in caller whatever their role.
     */
    private const BASE_ABILITIES = ['wallet:read', 'profile:write'];

    /**
     * Issue a token for one device, replacing anything that device held.
     *
     * @param array{id?: string|null, name?: string|null, platform?: string|null, app_version?: string|null} $device
     */
    public function issue(User $user, array $device = [], ?string $ip = null): NewAccessToken
    {
        $deviceId = $this->normaliseDeviceId($device['id'] ?? null);

        if ($deviceId !== null) {
            $this->revokeDevice($user, $deviceId);
        }

        $token = $user->createToken(
            $this->tokenName($device),
            $this->abilitiesFor($user),
        );

        $token->accessToken->forceFill([
            'device_id'   => $deviceId,
            'device_name' => $this->trimmed($device['name'] ?? null, 120),
            'platform'    => $this->trimmed($device['platform'] ?? null, 20),
            'app_version' => $this->trimmed($device['app_version'] ?? null, 20),
            'issued_ip'   => $this->trimmed($ip, 45),
        ])->save();

        return $token;
    }

    /**
     * Revoke whatever the given device holds.
     *
     * A null or blank device id revokes nothing. Without that guard the query
     * would match `device_id IS NULL`, which is every token ever minted from
     * the web dashboard - a caller signing in from a client that sent no
     * device id would sign every other integration out.
     *
     * @return int Tokens deleted.
     */
    public function revokeDevice(User $user, ?string $deviceId): int
    {
        $deviceId = $this->normaliseDeviceId($deviceId);

        if ($deviceId === null) {
            return 0;
        }

        return (int) $user->tokens()->where('device_id', $deviceId)->delete();
    }

    /**
     * Sign every other device out, keeping the one making the request.
     *
     * Their push registrations go too - see forgetPushExcept() - so a phone
     * signed out from here stops receiving notifications as well as requests.
     *
     * @return int Tokens deleted.
     */
    public function revokeAllExcept(User $user, int|string|null $keepTokenId): int
    {
        return DB::transaction(function () use ($user, $keepTokenId): int {
            $query = $user->tokens();

            $keptDeviceId = null;

            if ($keepTokenId !== null) {
                $keptDeviceId = $this->normaliseDeviceId($user->tokens()->whereKey($keepTokenId)->value('device_id'));

                $query->whereKeyNot($keepTokenId);
            }

            $this->forgetPushExcept($user, $keptDeviceId);

            return (int) $query->delete();
        });
    }

    /**
     * Sign one device out from somewhere else - the device list's button for a
     * lost phone - and stop pushing to it.
     *
     * Not revokeDevice(): that one runs when the SAME phone signs in again,
     * and must leave its push registration alone. A token minted with no
     * device id cannot be matched to a registration, so only the token goes.
     */
    public function signOut(User $user, PersonalAccessToken $token): void
    {
        $deviceId = $this->normaliseDeviceId($token->device_id);

        DB::transaction(function () use ($user, $token, $deviceId): void {
            $token->delete();

            if ($deviceId !== null) {
                $user->pushTokens()->whereRaw('LOWER(TRIM(device_id)) = ?', [$deviceId])->delete();
            }
        });
    }

    /**
     * Drop every push registration except the one on the device being kept.
     *
     * Compared lower-cased and trimmed, on purpose. The access token's device
     * id went through normaliseDeviceId(); the push registration's is stored
     * exactly as the app sent it, and an iPhone sends an upper-case UUID. A
     * plain equality match missed every iPhone - and dropped the caller's own
     * registration while leaving the lost phone's in place.
     *
     * A registration that carried no device id is dropped as well: it cannot
     * be tied to any session, so there is no telling whether it is the phone
     * that asked or the one that was lost. A phone still signed in registers
     * again the next time the app starts; a lost one would otherwise receive
     * amounts indefinitely. With no device to keep, everything goes.
     */
    private function forgetPushExcept(User $user, ?string $keptDeviceId): void
    {
        $query = $user->pushTokens();

        if ($keptDeviceId !== null) {
            $query->where(function ($inner) use ($keptDeviceId): void {
                $inner->whereNull('device_id')
                    ->orWhereRaw('LOWER(TRIM(device_id)) != ?', [$keptDeviceId]);
            });
        }

        $query->delete();
    }

    /**
     * What a token for this user is allowed to do.
     *
     * The role ability is what the route groups gate on, so a customer's token
     * can never reach an agent or merchant route however the request is
     * shaped.
     *
     * @return list<string>
     */
    public function abilitiesFor(User $user): array
    {
        $role = $user->role instanceof UserRole ? $user->role : UserRole::from((string) $user->role);

        return array_values(array_unique([
            'role:'.$role->value,
            ...self::BASE_ABILITIES,
            ...match ($role) {
                UserRole::USER     => ['wallet:write'],
                UserRole::AGENT    => ['wallet:write', 'agent:operate'],
                UserRole::MERCHANT => ['wallet:write', 'merchant:manage'],
            },
        ]));
    }

    /**
     * The token's Sanctum name, which is what a person sees in a device list.
     */
    private function tokenName(array $device): string
    {
        return $this->trimmed($device['name'] ?? null, 120)
            ?? $this->trimmed($device['platform'] ?? null, 20)
            ?? 'mobile';
    }

    private function normaliseDeviceId(?string $deviceId): ?string
    {
        $deviceId = $this->trimmed($deviceId, 64);

        return $deviceId === null ? null : Str::lower($deviceId);
    }

    private function trimmed(?string $value, int $limit): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
