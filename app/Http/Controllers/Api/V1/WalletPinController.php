<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Api\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\WalletPinUpdateRequest;
use App\Services\Money\WalletPinGuard;
use App\Services\Money\WalletPinResetLink;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The wallet PIN, from the app.
 *
 * Mirrors the settings page's WalletPinController, and shares everything that
 * decides anything with it: the rules are the website's own form request, the
 * counter behind the current-credential check is WalletPinGuard's - the ONE
 * counter the money flows draw on - and the reset email is WalletPinResetLink.
 * So a PIN the website refuses is refused here, and a guess made here costs
 * the same as a guess made on send money.
 *
 * Nothing here ever returns the PIN or its hash.
 */
class WalletPinController extends Controller
{
    /**
     * Whether a PIN is set, and whether guessing has locked it.
     *
     * The lockout is reported so the app can say "try again in N minutes"
     * before the customer types anything, rather than after.
     */
    public function show(Request $request, WalletPinGuard $pins): JsonResponse
    {
        $user    = $request->user();
        $seconds = $pins->lockedFor($user);

        return ApiResponse::ok([
            'is_set'      => $user->hasWalletPin(),
            'locked'      => $seconds > 0,
            'retry_after' => $seconds,
        ]);
    }

    /**
     * Set the first PIN, or change it.
     *
     * By the time this runs the form request has confirmed the current
     * credential through the guard - the current PIN, or the account password
     * for a customer who has none yet or has forgotten it.
     */
    public function update(WalletPinUpdateRequest $request): JsonResponse
    {
        $user = $request->user();

        if (isDemoProtectedAccount($user->email)) {
            return ApiResponse::error(
                ApiErrorCode::Forbidden,
                __('Wallet PIN changes are disabled for the shared demo account.'),
            );
        }

        $user->wallet_pin = $request->validated('pin');
        $user->save();

        Log::info('Wallet PIN updated', [
            'user_id' => $user->id,
            'ip'      => $request->ip(),
        ]);

        return ApiResponse::ok(['is_set' => true]);
    }

    /**
     * Email the link that clears a forgotten PIN.
     *
     * Only to an address the customer has proved: the link clears the PIN,
     * so sending it to an unconfirmed address would let whoever typed that
     * address in take the wallet's second factor away.
     */
    public function reset(Request $request, WalletPinResetLink $links): JsonResponse
    {
        $user = $request->user();

        if (isDemoProtectedAccount($user->email)) {
            return ApiResponse::error(
                ApiErrorCode::Forbidden,
                __('Wallet PIN resets are disabled for the shared demo account.'),
            );
        }

        if (! $user->email_verified_at) {
            return ApiResponse::error(
                ApiErrorCode::EmailUnverified,
                __('Verify your email address before resetting the wallet PIN.'),
            );
        }

        $links->send($user, $request->ip());

        return ApiResponse::ok(['sent' => true]);
    }
}
