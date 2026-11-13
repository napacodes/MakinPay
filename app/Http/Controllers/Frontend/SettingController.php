<?php

namespace App\Http\Controllers\Frontend;

use App\Enums\Gender;
use App\Enums\WalletEarnStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateUserPasswordRequest;
use App\Models\Currency;
use App\Services\Security\PasswordChanger;
use App\Support\AccountPhone;
use App\Traits\FileManageTrait;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class SettingController extends Controller
{
    use FileManageTrait;

    public function profile()
    {
        $user = auth()->user();

        // The Preferences section is guarded by `isset($currencies)` and this
        // was never passed, so the whole block — a "Default Currency" select
        // bound to a column that does not exist — silently never rendered.
        // It is now the display-currency picker and has something to list.
        //
        // Only currencies that can actually be converted through are offered:
        // presenting a total via a stale or placeholder rate is how a customer
        // is shown a figure nobody can explain.
        $currencies = Currency::query()
            ->active()
            ->orderBy('name')
            ->get()
            ->filter(fn (Currency $currency): bool => $currency->isConvertible())
            ->values();

        return view('frontend.user.setting.profile', compact('user', 'currencies'));
    }

    public function profileUpdate(Request $request)
    {
        $user = auth()->user();

        // In demo mode the seeded sandbox accounts (users/merchants/agents
        // listed in the public CodeCanyon demo credentials) must stay
        // logged-in-able for every visitor — so they cannot rotate their
        // own email. Anything outside the demo allowlist is unaffected.
        if (isDemoProtectedAccount($user->email)) {
            notifyEvs('error', __('Profile updates are disabled for the shared demo account.'));

            return redirect()->back();
        }

        // validation rules
        $validate = $request->validate([
            'avatar'           => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'first_name'       => 'nullable',
            'last_name'        => 'nullable',
            'business_name'    => 'nullable',
            'business_address' => 'nullable',
            'username'         => 'required|unique:users,username,'.$user->id,
            'gender'           => ['required', new Enum(Gender::class)],
            'birthday'         => 'nullable|date',
            'phone'            => [
                'bail',
                'nullable',
                'string',
                function (string $attribute, mixed $value, Closure $fail) use ($user): void {
                    if (AccountPhone::isHeldByAnotherAccount($user, $value)) {
                        $fail(__('This phone number is already registered.'));
                    }
                },
            ],
            'country' => [
                'nullable',
                function (string $attribute, mixed $value, Closure $fail) use ($user): void {
                    if (strtoupper(trim((string) $value)) !== strtoupper(trim((string) $user->country))) {
                        $fail(__('Your country is set when you register and cannot be changed here. Contact support if it is wrong.'));
                    }
                },
            ],
            'state'       => 'nullable',
            'city'        => 'nullable',
            'postal_code' => 'nullable',
            'address'     => 'nullable',
            'email'       => 'required|unique:users,email,'.$user->id,

            // Presentation only. Restricted to active currencies so a disabled
            // one cannot be pinned; `User::displayCurrency()` additionally
            // falls back to the base if the rate later becomes unusable, so a
            // currency going stale after the fact cannot produce a wrong total.
            'display_currency' => [
                'nullable',
                'string',
                Rule::exists('currencies', 'code')->where('status', true),
            ],
        ]);

        // The country is set at registration and only an admin may correct
        // it: tax rules read local or international from it. The form shows
        // it disabled; a posted copy of the same value is dropped, any other
        // was refused above.
        unset($validate['country']);

        // An empty select means "follow the site base", which is null rather
        // than an empty string — otherwise displayCurrency() would compare ''
        // against a code on every read.
        $validate['display_currency'] = ($validate['display_currency'] ?? '') !== ''
            ? strtoupper((string) $validate['display_currency'])
            : null;

        // if user uploaded a new avatar, update the avatar
        if ($request->hasFile('avatar')) {
            $validate['avatar'] = $this->uploadImage($request->file('avatar'), $user->avatar);
        }

        if ($user->email !== $validate['email']) {
            $validate['email_verified_at'] = null;
        }

        // Stored as sign-up stores it, or phone sign-in and the uniqueness
        // check above would never find the number again.
        if (array_key_exists('phone', $validate)) {
            $validate['phone'] = AccountPhone::forAccount($user, $validate['phone']);
        }

        if ((string) $user->phone !== (string) ($validate['phone'] ?? '')) {
            $validate['phone_verified_at']          = null;
            $validate['phone_verification_enabled'] = false;
        }

        // update the user
        $user->update($validate);

        notifyEvs('success', 'Profile updated successfully');

        // return the user back to the form with a success message
        return redirect()->back();
    }

    public function verifyEmail()
    {

        if (auth()->user()->hasVerifiedEmail()) {
            notifyEvs('warning', 'Your email address is already verified');

            return redirect()->intended(route('user.settings.profile'));
        }

        if (! auth()->user()->trySendEmailVerificationNotification()) {
            notifyEvs('error', __('We could not send the verification email right now. Please try again in a few minutes or contact support.'));

            return redirect()->back();
        }

        notifyEvs('success', __('A fresh verification link has been sent to your email address'));

        // return the user back to the form with an error message
        return redirect()->back();
    }

    public function changePassword(): View
    {
        return view('frontend.user.setting.change_password');
    }

    public function subscriptionStatus(Request $request): View
    {
        $subscription = $request->user()
            ->activeSubscription()
            ->with(['plan.features', 'plan.prices'])
            ->first();

        return view('frontend.user.setting.subscription_status', compact('subscription'));
    }

    public function walletEarnStatus(Request $request): View
    {
        $activeStakes = $request->user()
            ->walletEarnStakes()
            ->with(['currency', 'plan'])
            ->where('status', WalletEarnStatus::Active)
            ->latest('starts_at')
            ->latest()
            ->get();

        $walletEarnSummary = [
            'active_count'     => $activeStakes->count(),
            'principal_amount' => (float) $activeStakes->sum('principal_amount'),
            'expected_profit'  => (float) $activeStakes->sum('expected_profit'),
            'paid_profit'      => (float) $activeStakes->sum('paid_profit'),
            'next_payout_at'   => $activeStakes
                ->pluck('next_payout_at')
                ->filter()
                ->sort()
                ->first(),
        ];

        return view('frontend.user.setting.wallet_earn_status', compact('activeStakes', 'walletEarnSummary'));
    }

    public function passwordUpdate(UpdateUserPasswordRequest $request, PasswordChanger $passwords): RedirectResponse
    {
        $user = $request->user();

        // Demo-mode lockdown: seeded sandbox accounts may not rotate
        // their password, otherwise the next evaluator who tries to
        // log in with the publicly listed credentials gets locked out.
        if (isDemoProtectedAccount($user->email)) {
            notifyEvs('error', __('Password changes are disabled for the shared demo account.'));

            return redirect()->back();
        }

        $validated = $request->validated();

        Auth::logoutOtherDevices($validated['old_password']);

        // Other browser sessions go above; app tokens go here. A web session
        // holds no token of its own to keep, so every one of them goes.
        $passwords->change($user, $validated['password']);

        $request->session()->put('password_hash_web', $user->getAuthPassword());

        notifyEvs('success', 'Password updated successfully');

        return redirect()->back();
    }
}
