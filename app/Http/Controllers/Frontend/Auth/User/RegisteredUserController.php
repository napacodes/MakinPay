<?php

namespace App\Http\Controllers\Frontend\Auth\User;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UserRegisterRequest;
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
        return view(themedView('frontend.auth.user.register'));
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(UserRegisterRequest $request): RedirectResponse
    {
        $user = $this->registration->register(
            UserRole::USER,
            $request->validated(),
            Cookie::get('referral_code'),
        );

        Auth::login($user);

        notifyEvs('success', __('Registration successful.'));

        return redirect()->route('user.dashboard');
    }
}
