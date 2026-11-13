<?php

namespace App\Http\Controllers\Frontend\Auth\Agent;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AgentRegisterRequest;
use App\Models\User;
use App\Services\AgentService;
use App\Services\Auth\RegistrationService;
use App\Services\FeatureManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RegisteredUserController extends Controller
{
    public function __construct(
        private readonly FeatureManager $features,
        private readonly AgentService $agents,
        private readonly RegistrationService $registration,
    ) {}

    /**
     * Display the registration view.
     */
    public function create(): View
    {
        $this->ensureFeatureEnabled();
        $this->ensureSelfRegistrationAllowed();

        return view(themedView('frontend.auth.agent.register'));
    }

    public function store(AgentRegisterRequest $request): RedirectResponse
    {
        $this->ensureFeatureEnabled();
        $this->ensureSelfRegistrationAllowed();

        /*
         * The agent profile is built INSIDE the registration transaction. It
         * used to run after it, and AgentService::createDefaultForUser throws
         * when the site has no active currency - which left the customer as an
         * AGENT with no agent profile, able to sign in with nothing there.
         */
        $user = $this->registration->register(
            UserRole::AGENT,
            $request->validated(),
            Cookie::get('referral_code'),
            fn (User $agent) => $this->agents->createDefaultForUser($agent),
        );

        Auth::login($user);

        notifyEvs('success', __('Agent registration successful.'));

        return redirect()->route('user.dashboard');
    }

    private function ensureFeatureEnabled(): void
    {
        $byFeatureManager = $this->features->isEnabled('agent_program');
        $bySetting        = (bool) setting('agent_program_enabled', true);

        if (! $byFeatureManager || ! $bySetting) {
            throw new NotFoundHttpException;
        }
    }

    private function ensureSelfRegistrationAllowed(): void
    {
        if (! (bool) setting('agent_self_registration', true)) {
            throw new NotFoundHttpException;
        }
    }
}
