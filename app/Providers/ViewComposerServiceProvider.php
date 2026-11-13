<?php

namespace App\Providers;

use App\Enums\TrxType;
use App\Models\FooterSection;
use App\Models\Language;
use App\Models\Navigation;
use App\Models\Social;
use App\Support\AdminShell;
use App\Support\AdminSidebarIndicatorManager;
use App\Support\QuickMenu;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class ViewComposerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->composeLanguages();
        $this->composeUserNotifications();
        $this->composeSocialLinks();
        $this->composeNavigation();
        $this->composeFooter();
        $this->composeAdminPermissions();
        $this->composeQuickFunctions();
        $this->composeSignupBonusPopup();
    }

    /**
     * Share active languages with caching across needed views.
     */
    protected function composeLanguages(): void
    {
        View::composer([
            'backend.layouts.partials._header',
            'frontend.layouts.user.partials._mobile_navbar',
            'frontend.layouts.user.partials._mobile_app_header',
            'frontend.layouts.user.partials._navbar',
            'frontend.layouts.partials._language_switcher',
            'frontend.layouts.golden.partials._language_switcher',
        ], function ($view) {
            $languages = Language::activeCached();

            $view->with('languages', $languages);
        });
    }

    /**
     * Share user unread notifications across frontend navbar views.
     */
    protected function composeUserNotifications(): void
    {
        app()->singleton('unreadNotificationsData', function () {
            $user = auth()->user();

            if (! $user) {
                return [
                    'count'         => 0,
                    'notifications' => collect(),
                ];
            }

            $allUnread = $user->unreadNotifications()->latest()->get();

            return [
                'count'         => $allUnread->count(),
                'notifications' => $allUnread,
            ];
        });

        View::composer([
            'frontend.layouts.user.partials._navbar',
            'frontend.layouts.user.partials._mobile_navbar',
            'frontend.layouts.user.partials._mobile_app_header',
        ], function ($view) {
            $notificationData = app('unreadNotificationsData');

            $view->with([
                'notifications'     => $notificationData['notifications'],
                'notificationCount' => $notificationData['count'],
            ]);
        });
    }

    /**
     * Inject the pending signup-bonus popup payload into the user layout.
     *
     * Resolves to `null` (so the partial renders nothing) unless the
     * authenticated user has been awarded a bonus that they haven't
     * acknowledged yet. The payload mirrors the design's variables
     * exactly: name, amount, currency, tier, walletUrl, transactionUrl.
     */
    protected function composeSignupBonusPopup(): void
    {
        View::composer(['frontend.layouts.user.index'], function ($view): void {
            $user = auth()->user();

            $payload = null;

            if ($user
                && $user->signup_bonus_awarded_at !== null
                && $user->signup_bonus_seen_at === null
            ) {
                $transaction = $user->transactions()
                    ->where('trx_type', TrxType::SIGNUP_BONUS)
                    ->latest('id')
                    ->first();

                if ($transaction) {
                    $payload = [
                        'name'           => $user->first_name ?? $user->name,
                        'amount'         => (float) $transaction->amount,
                        'currency'       => $transaction->payable_currency ?? siteCurrency(),
                        'tier'           => $user->role->title().' Bonus',
                        'walletUrl'      => route('user.wallet.index'),
                        'transactionUrl' => route('user.transaction.index'),
                        'acknowledgeUrl' => route('user.signup-bonus.acknowledge'),
                    ];
                }
            }

            $view->with('signupBonusPopup', $payload);
        });
    }

    protected function composeQuickFunctions(): void
    {

        View::composer([
            'frontend.layouts.user.partials._quick_functions',
            'frontend.layouts.user.partials._mobile_app_header',
        ], function ($view) {
            $catalog        = $this->getQuickLinks();
            $mainCount      = 6;
            $quickLinksMain = array_slice($catalog, 0, $mainCount);
            $quickLinksMore = array_slice($catalog, $mainCount);
            $view->with([
                'quickLinksMain'        => $quickLinksMain,
                'quickLinksMore'        => $quickLinksMore,
                'quickMenuSelectedKeys' => QuickMenu::savedKeysFor(auth()->user()),
            ]);
        });
    }

    /**
     * Share active social links in frontend parts.
     */
    protected function composeSocialLinks(): void
    {
        View::composer([
            'frontend.layouts.partials._offcanvas',
            'frontend.layouts.partials._header_top',
            // Golden theme — top strip + footer both render social icons
            'frontend.layouts.golden.partials._topstrip',
            'frontend.layouts.golden.partials._footer',
            'frontend.layouts.emerald.partials._footer',
            'frontend.layouts.aurora.partials._footer',
        ], function ($view) {
            $socials = Social::activeCached();

            $view->with('socials', $socials);
        });
    }

    protected function composeNavigation()
    {
        View::composer([
            'frontend.layouts.partials._menu_list',
            'frontend.layouts.golden.partials._menu_list',
            'frontend.layouts.emerald.partials._header',
            'frontend.layouts.aurora.partials._header',
        ], function ($view) {
            $navigations = Navigation::activeCached();
            $view->with('navigations', $navigations);
        });
    }

    protected function composeFooter()
    {
        View::composer([
            'frontend.layouts.partials._footer',
            'frontend.layouts.golden.partials._footer',
            'frontend.layouts.emerald.partials._footer',
            'frontend.layouts.aurora.partials._footer',
        ], function ($view) {
            $footers = FooterSection::activeCached();
            $view->with('footers', $footers);
        });
    }

    private function composeAdminPermissions()
    {
        view()->composer('backend.layouts.partials._sidebar', function ($view) {
            if (Auth::guard('admin')->check() && ! session()->has('admin_permissions')) {
                session([
                    'admin_permissions' => Auth::guard('admin')->user()->getAllPermissions()->pluck('name')->toArray(),
                ]);
            }
        });

        View::composer(['backend.layouts.partials._sidebar', 'backend.layouts.partials._header'], function ($view) {
            $admin = Auth::guard('admin')->user();

            if ($admin && ! session()->has('admin_permissions')) {
                session([
                    'admin_permissions' => $admin->getAllPermissions()->pluck('name')->toArray(),
                ]);
            }

            /*
             * Fill in, do not overwrite. A caller that already has these -
             * a test rendering the partial on its own, or a page composing
             * it with known values - keeps what it passed.
             */
            $supplied = $view->getData();

            $defaults = [
                'adminMenuBadges' => static fn (): array => AdminSidebarIndicatorManager::build(session('admin_permissions', [])),
                'adminShellState' => static fn (): array => AdminShell::state(),
                'adminBreadcrumb' => static fn (): array => AdminShell::breadcrumb(),
            ];

            foreach ($defaults as $key => $resolve) {
                if (! array_key_exists($key, $supplied)) {
                    $view->with($key, $resolve());
                }
            }
        });
    }

    /**
     * Build the quick-function links for the authenticated user.
     *
     * The catalog itself lives in {@see QuickMenu} so that the panel, the
     * editor and the endpoint that saves a personal selection all agree on
     * which shortcuts exist and which of them this user may see.
     *
     * @return array<int, array{key: string, title: string, icon: string, link: string}>
     */
    private function getQuickLinks(): array
    {
        return QuickMenu::catalogFor(auth()->user());
    }
}
