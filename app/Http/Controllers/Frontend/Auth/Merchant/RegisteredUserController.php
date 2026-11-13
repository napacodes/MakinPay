<?php

namespace App\Http\Controllers\Frontend\Auth\Merchant;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\MerchantRegisterRequest;
use App\Services\Auth\RegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function __construct(private readonly RegistrationService $registration) {}

    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view(themedView('frontend.auth.merchant.register'));
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(MerchantRegisterRequest $request): RedirectResponse
    {
        $user = $this->registration->register(
            UserRole::MERCHANT,
            $request->validated(),
            Cookie::get('referral_code'),
        );

        Auth::login($user);

        notifyEvs('success', __('Merchant registration successful.'));

        return redirect()->route('user.dashboard');
    }
}
