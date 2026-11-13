<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\Api\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DeviceResource;
use App\Models\UserPushToken;
use App\Services\Api\DeviceTokenService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where this account is signed in, and how to stop it.
 *
 * Every query starts from $request->user()->tokens(). Not one of them takes an
 * id and looks it up globally, because a device list is the most obvious place
 * in an API to be able to read or revoke somebody else's session by guessing a
 * number.
 */
class DeviceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $devices = $request->user()
            ->tokens()
            ->latest('last_used_at')
            ->latest('created_at')
            ->get();

        return ApiResponse::ok(DeviceResource::collection($devices));
    }

    /**
     * Sign one device out.
     *
     * A device that is not this customer's is reported as not found rather
     * than as forbidden. Forbidden would confirm the id exists, which is the
     * one fact worth hiding here.
     */
    public function destroy(Request $request, string $device, DeviceTokenService $tokens): JsonResponse
    {
        $token = $request->user()->tokens()->whereKey($device)->first();

        if ($token === null) {
            return ApiResponse::error(ApiErrorCode::ResourceNotFound);
        }

        $wasCurrent = $request->user()->currentAccessToken()?->getKey() === $token->getKey();

        // The token and this phone's push registration, so a phone signed
        // out from here stops receiving notifications as well.
        $tokens->signOut($request->user(), $token);

        // Saying so lets the app drop straight to the sign-in screen instead
        // of discovering on its next call that it no longer has a token.
        return ApiResponse::ok(['signed_out' => true, 'was_current_device' => $wasCurrent]);
    }

    /**
     * Register this handset for push.
     *
     * Keyed on the device rather than on the token, so an operating system
     * that rotates a registration token updates the row instead of leaving a
     * dead one behind that every later notification still tries to reach.
     *
     * Registering is separate from signing in on purpose: a push registration
     * belongs to the app INSTALL and outlives any one session, so it is not
     * something the login response can carry.
     */
    public function storePushToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token'       => ['required', 'string', 'max:4096'],
            'device_id'   => ['nullable', 'string', 'max:64'],
            'platform'    => ['nullable', 'string', 'max:20'],
            'app_version' => ['nullable', 'string', 'max:20'],
        ]);

        UserPushToken::register($request->user(), $validated['token'], [
            'id'          => $validated['device_id']   ?? null,
            'platform'    => $validated['platform']    ?? null,
            'app_version' => $validated['app_version'] ?? null,
        ]);

        return ApiResponse::ok(['registered' => true]);
    }

    /**
     * Stop pushing to this handset.
     *
     * Scoped to the caller, and answered the same way whether or not there was
     * anything to delete: a customer signing out of an app that had already
     * lost its registration should not be told the difference, and there is
     * nothing they could do about it either way.
     */
    public function destroyPushToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token'     => ['nullable', 'string', 'max:4096'],
            'device_id' => ['nullable', 'string', 'max:64'],
        ]);

        $query = $request->user()->pushTokens();

        if (filled($validated['token'] ?? null)) {
            $query->where('token', $validated['token']);
        } elseif (filled($validated['device_id'] ?? null)) {
            $query->where('device_id', $validated['device_id']);
        }

        // Neither given means "this account is signing out everywhere" - the
        // same shape as logout-all, and the only reading that does not
        // silently delete nothing.
        $query->delete();

        return ApiResponse::ok(['unregistered' => true]);
    }
}
