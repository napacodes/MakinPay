<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Api\ApiErrorCode;
use App\Exceptions\NotifyErrorException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use App\Http\Requests\UpdateUserPasswordRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\Auth\AccountClosureService;
use App\Services\Security\PasswordChanger;
use App\Support\AccountPhone;
use App\Support\ApiResponse;
use App\Traits\FileManageTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The account as its owner edits it.
 *
 * Mirrors SettingController::profileUpdate, including the two consequences
 * that are easy to forget and expensive to get wrong: changing the address
 * un-verifies it, and changing the number un-verifies that and switches phone
 * verification off. A profile edit must not be a way to keep a verified badge
 * against a value nobody has proved.
 */
class ProfileController extends Controller
{
    use FileManageTrait;

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::ok(new UserResource($request->user()));
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($refusal = $this->refuseForDemo($user->email)) {
            return $refusal;
        }

        $values = $request->validated();

        // A verified identity locks the name it was verified under: the app
        // shows the fields read-only, and this is what makes that true.
        if ($user->isKycVerified()) {
            $locked = array_filter(['first_name', 'last_name'], fn (string $key): bool => array_key_exists($key, $values) && trim((string) $values[$key]) !== trim((string) $user->{$key}));
            if ($locked !== []) {
                throw ValidationException::withMessages(array_fill_keys($locked, __('Your name is locked to your verified identity. Contact support to change it.')));
            }
        }

        $this->refuseCountryChange($user, $values);
        unset($values['country']);

        // An empty selection means follow the site base, which is null rather than an
        // empty string, or displayCurrency() compares '' against a code on
        // every read.
        if (array_key_exists('display_currency', $values)) {
            $values['display_currency'] = filled($values['display_currency'])
                ? strtoupper((string) $values['display_currency'])
                : null;
        }

        if (array_key_exists('email', $values) && $user->email !== $values['email']) {
            $values['email_verified_at'] = null;
        }

        if (array_key_exists('phone', $values)) {
            $values['phone'] = AccountPhone::forAccount($user, $values['phone']);
        }

        if (array_key_exists('phone', $values) && (string) $user->phone !== (string) $values['phone']) {
            $values['phone_verified_at']          = null;
            $values['phone_verification_enabled'] = false;
        }

        $emailChanged = array_key_exists('email_verified_at', $values);

        $user->update($values);
        $user = $user->fresh();

        // A changed address is asked to prove itself straight away; the
        // verified badge is already gone, and sending waits on the answer.
        $sent = $emailChanged && $user->trySendEmailVerificationNotification();

        return ApiResponse::ok((new UserResource($user))->toArray($request) + [
            'email_verification_sent' => $sent,
        ]);
    }

    /**
     * The country is set at registration and only an admin may correct it:
     * what is local or international, and so which tax rules apply, is read
     * from it. Sending the country the account already has is accepted, so a
     * client may echo the whole profile back; any other value is refused.
     *
     * @param array<string, mixed> $values
     *
     * @throws ValidationException
     */
    private function refuseCountryChange(User $user, array $values): void
    {
        if (! array_key_exists('country', $values)) {
            return;
        }

        $sent    = strtoupper(trim((string) $values['country']));
        $current = strtoupper(trim((string) $user->country));

        if ($sent !== $current) {
            throw ValidationException::withMessages([
                'country' => __('Your country is set when you register and cannot be changed here. Contact support if it is wrong.'),
            ]);
        }
    }

    /**
     * Replace the profile picture.
     *
     * Separate from update() because a phone sends a picture as multipart and
     * everything else as JSON, and because the old file has to be removed -
     * uploadImage takes the previous path precisely so it can.
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
        ]);

        $user = $request->user();

        if ($refusal = $this->refuseForDemo($user->email)) {
            return $refusal;
        }

        $user->update([
            'avatar' => $this->uploadImage($request->file('avatar'), $user->avatar),
        ]);

        return ApiResponse::ok(new UserResource($user->fresh()));
    }

    /**
     * Shared demo logins are published on the sign-in page. If one could
     * rotate its own address, the next visitor would find a demo they cannot
     * sign in to.
     */
    /**
     * Change the password while signed in.
     *
     * What a change does belongs to PasswordChanger, shared with the settings
     * page and the reset, and the rules are the website's own form request -
     * so the app cannot accept a password the website would refuse. What is
     * this endpoint's own is which token survives: the one asking, so the
     * customer is not signed out of the phone in their hand. A caller signed in
     * by session has no token to keep, and every token goes, as on the website.
     */
    public function changePassword(UpdateUserPasswordRequest $request, PasswordChanger $passwords): JsonResponse
    {
        $user = $request->user();

        if (isDemoProtectedAccount($user->email)) {
            return ApiResponse::error(
                ApiErrorCode::Forbidden,
                __('Password changes are disabled for the shared demo account.'),
            );
        }

        $token = $user->currentAccessToken();

        $revoked = $passwords->change(
            $user,
            (string) $request->validated('password'),
            $token instanceof PersonalAccessToken ? $token->getKey() : null,
        );

        return ApiResponse::ok([
            'password_changed'   => true,
            'signed_out_devices' => $revoked,
        ]);
    }

    /**
     * Whether the account may close now, and if not, what to finish first -
     * so the app can say so before asking for the password.
     */
    public function closure(Request $request, AccountClosureService $closure): JsonResponse
    {
        $blockers = $closure->blockers($request->user());

        return ApiResponse::ok([
            'can_close' => $blockers === [],
            'blockers'  => $this->blockerList($blockers),
        ]);
    }

    /**
     * Close the account for good, with the password and - when it is on - a
     * code from the authenticator, exactly as on the website. Every token,
     * this one included, is revoked; the app signs out on the answer.
     */
    public function close(Request $request, AccountClosureService $closure): JsonResponse
    {
        $validated = $request->validate([
            'password'        => ['required', 'string', 'max:255'],
            'two_factor_code' => [$request->user()->two_factor_enabled ? 'required' : 'nullable', 'string', 'max:16'],
        ]);

        $blockers = $closure->blockers($request->user());

        if ($blockers !== []) {
            return ApiResponse::error(
                ApiErrorCode::ValidationFailed,
                __('Your account cannot be closed yet.'),
                [],
                null,
                [],
                ['blockers' => $this->blockerList($blockers)],
            );
        }

        try {
            $closure->close($request->user(), (string) $validated['password'], $validated['two_factor_code'] ?? null);
        } catch (NotifyErrorException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage());
        }

        return ApiResponse::ok(['closed' => true]);
    }

    /**
     * @param  array<string, string>                      $blockers
     * @return list<array{code: string, message: string}>
     */
    private function blockerList(array $blockers): array
    {
        return collect($blockers)
            ->map(fn (string $message, string $code): array => ['code' => $code, 'message' => $message, 'action' => self::BLOCKER_ACTIONS[$code] ?? null])
            ->values()
            ->all();
    }

    /**
     * Where in the app each blocker is resolved.
     */
    private const BLOCKER_ACTIONS = [
        'balance'             => '/home/withdraw',
        'pending_transaction' => '/activity',
        'open_p2p_order'      => '/more/p2p',
        'merchant_profile'    => '/more/support',
        'agent_profile'       => '/more/support',
        'virtual_card'        => '/more/cards',
        'wallet_earn_stake'   => '/more/earn',
        'vault_balance'       => '/home/vaults',
    ];

    private function refuseForDemo(?string $email): ?JsonResponse
    {
        if (! isDemoProtectedAccount($email)) {
            return null;
        }

        return ApiResponse::error(
            ApiErrorCode::Forbidden,
            __('Profile updates are disabled for the shared demo account.'),
        );
    }
}
