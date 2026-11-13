<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Api\ApiErrorCode;
use App\Enums\LoginRisk;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DeviceResource;
use App\Models\LoginActivity;
use App\Models\User;
use App\Services\Api\DeviceTokenService;
use App\Services\Security\AccountLockdown;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The security centre from the app: what protects the account and when
 * it was last touched, where it is signed in, the sign-ins the risk rules
 * flagged, and the one-tap lockdown.
 */
class SecurityController extends Controller
{
    public function __construct(private readonly DeviceTokenService $tokens) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $last = LoginActivity::query()->where('user_id', $user->id)->where('succeeded', true)->latest('login_at')->latest('id')->first();

        return ApiResponse::ok([
            'password_changed_at' => $user->password_changed_at?->toIso8601String(),
            'two_factor_enabled'  => (bool) $user->two_factor_enabled,
            'has_wallet_pin'      => $user->wallet_pin        !== null,
            'email_verified'      => $user->email_verified_at !== null,
            'phone_verified'      => $user->phone_verified_at !== null,
            'kyc_verified'        => $user->isKycVerified(),
            'sessions'            => $user->tokens()->count(),
            'last_sign_in'        => $last === null ? null : $this->signIn($last),
            'flagged_sign_ins'    => $this->flagged($user)->map(fn (LoginActivity $row): array => $this->signIn($row))->values(),
        ]);
    }

    /**
     * Where this account is signed in, each device with the place its
     * token was minted from, when the sign-in history knows it.
     */
    public function sessions(Request $request): JsonResponse
    {
        $user    = $request->user();
        $devices = $user->tokens()->latest('last_used_at')->latest('created_at')->get();
        $places  = LoginActivity::query()
            ->where('user_id', $user->id)
            ->where('succeeded', true)
            ->whereIn('ip_address', $devices->pluck('issued_ip')->filter()->unique()->all())
            ->latest('login_at')
            ->get()
            ->unique('ip_address')
            ->keyBy('ip_address');

        return ApiResponse::ok($devices->map(function (PersonalAccessToken $token) use ($request, $places): array {
            $place = $token->issued_ip ? $places->get($token->issued_ip) : null;

            return (new DeviceResource($token))->toArray($request) + [
                'location' => $place?->location(),
            ];
        })->values());
    }

    /**
     * Sign every other device out; this one stays.
     */
    public function revokeOthers(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken();

        return ApiResponse::ok([
            'signed_out_devices' => $this->tokens->revokeAllExcept($request->user(), $current instanceof PersonalAccessToken ? $current->getKey() : null),
        ]);
    }

    /**
     * "That was me": the flagged sign-in stops being raised.
     */
    public function acknowledge(Request $request, string $login): JsonResponse
    {
        $row = LoginActivity::query()->where('user_id', $request->user()->id)->whereKey($login)->first();
        if ($row === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }
        if ($row->acknowledged_at === null) {
            $row->forceFill(['acknowledged_at' => now()])->save();
        }

        return ApiResponse::ok(['acknowledged' => true]);
    }

    public function lockdown(Request $request, AccountLockdown $lockdown): JsonResponse
    {
        $user = $request->user();
        if (isDemoProtectedAccount($user->email)) {
            return ApiResponse::error(ApiErrorCode::Forbidden, __('Lockdown is disabled for the shared demo account.'));
        }

        $current = $user->currentAccessToken();

        return ApiResponse::ok($lockdown->run($user, $current instanceof PersonalAccessToken ? $current : null, $request->ip()));
    }

    /**
     * Sign-ins the risk rules flagged in the last three days that the
     * customer has not said were theirs.
     *
     * @return Collection<int, LoginActivity>
     */
    private function flagged(User $user): Collection
    {
        return LoginActivity::query()
            ->where('user_id', $user->id)
            ->where('succeeded', true)
            ->whereIn('risk', [LoginRisk::NEW_DEVICE->value, LoginRisk::SUSPICIOUS->value])
            ->whereNull('acknowledged_at')
            ->where('login_at', '>=', now()->subDays(3))
            ->latest('login_at')
            ->limit(5)
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function signIn(LoginActivity $row): array
    {
        $device = implode(' · ', array_filter([
            $row->device   !== 'Unknown' ? $row->device : null,
            $row->platform !== 'Unknown' ? $row->platform : null,
            $row->browser  !== 'Unknown' ? $row->browser : null,
        ]));

        return [
            'id'           => $row->id,
            'at'           => ($row->login_at ?? $row->created_at)?->toIso8601String(),
            'device'       => $device !== '' ? $device : null,
            'device_type'  => $row->device_type,
            'location'     => $row->location(),
            'city'         => $row->city,
            'country'      => $row->country,
            'ip'           => (string) $row->ip_address,
            'method'       => $row->method,
            'risk'         => $row->risk,
            'risk_reason'  => $row->risk_reason,
            'acknowledged' => $row->acknowledged_at !== null,
        ];
    }
}
