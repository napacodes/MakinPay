<?php

namespace App\Providers;

use App\Http\Middleware\EnsureEmailIsVerifiedWhenRequired;
use App\Listeners\AwardSignupBonusOnVerified;
use App\Listeners\RecordScheduledTaskRun;
use App\Listeners\RefreshMailSettingsBeforeJob;
use App\Models\Agent;
use App\Models\Merchant;
use App\Models\User;
use App\Observers\AgentObserver;
use App\Observers\MerchantObserver;
use App\Observers\UserObserver;
use App\Services\AppConfigService;
use App\Services\CurrencyService;
use App\Services\IpInfoService;
use App\Services\Observability\ErrorCollector;
use App\Services\Observability\ErrorFingerprinter;
use App\Services\Observability\RedactionPipeline;
use App\Services\Observability\TraceContext;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\Payout\PayoutGatewayFactory;
use App\Services\PaymentService;
use App\Services\QRCodeService;
use App\Services\Security\IpAbuseGuard;
use App\Services\Tax\TaxEngine;
use App\Services\TransactionService;
use App\Services\WalletService;
use App\Support\GatewayCallbackContext;
use App\Support\InstallationManager;
use App\Support\SafeTranslator;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Verified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register application services into the service container.
     */
    public function register(): void
    {
        $this->registerServices();
        $this->bindFacades();

        // Bind AppConfigService for application-wide configuration
        $this->app->singleton(AppConfigService::class, fn ($app) => new AppConfigService);
    }

    /**
     * Keep malformed or conflicting language groups from returning arrays to Blade.
     */
    protected function registerSafeTranslator(): void
    {
        $translator = new SafeTranslator($this->app->make('translation.loader'), $this->app->getLocale());
        $translator->setFallback($this->app->getFallbackLocale());

        $this->app->instance('translator', $translator);
        Facade::clearResolvedInstance('translator');
    }

    /**
     * Register singleton services for dependency injection.
     * - Use `singleton()` for shared instances across the application.
     * - Use `bind()` if a new instance is needed for each resolve.
     */
    protected function registerServices(): void
    {
        $this->app->singleton(CurrencyService::class, fn ($app) => new CurrencyService);
        $this->app->singleton(WalletService::class, fn ($app) => new WalletService);
        $this->app->singleton(TransactionService::class, fn ($app) => new TransactionService);
        $this->app->singleton(IpInfoService::class, fn ($app) => new IpInfoService);
        $this->app->singleton(QRCodeService::class, fn ($app) => new QRCodeService);

        // One factory for the whole request, so a gateway an add-on registers
        // with extend() is seen by PaymentService and the IPN controller alike.
        $this->app->singleton(PaymentGatewayFactory::class, fn () => new PaymentGatewayFactory);

        // Bind PaymentService with dependency injection
        $this->app->singleton(PaymentService::class, fn ($app) => new PaymentService($app->make(PaymentGatewayFactory::class)));

        $this->app->singleton(PayoutGatewayFactory::class, fn ($app) => new PayoutGatewayFactory);

        // GF6: one tax engine per request or queue job, so the rules are read
        // once however many methods a screen prices; scoped, not a singleton,
        // so a worker reads them afresh for every job.
        $this->app->scoped(TaxEngine::class, fn () => new TaxEngine);

        // Observability capture. Scoped rather than singleton: breadcrumbs
        // and the trace id belong to one request or one queue job, and a
        // worker that carried them between jobs would attribute an error to
        // whatever ran before it.
        $this->app->scoped(RedactionPipeline::class);
        $this->app->scoped(ErrorFingerprinter::class);
        $this->app->scoped(TraceContext::class);
        $this->app->scoped(ErrorCollector::class);
        $this->app->scoped('system.health', fn ($app) => $app->make(ErrorCollector::class));
    }

    /**
     * Bind services with aliases for Facade support.
     * This allows accessing services statically via Facades.
     */
    protected function bindFacades(): void
    {
        $this->app->singleton('currency.service', fn ($app) => $app->make(CurrencyService::class));
        $this->app->singleton('wallet.service', fn ($app) => $app->make(WalletService::class));
        // Which gateway the current request arrived through, if any. Scoped so
        // one request or queue job never inherits another's callback identity.
        $this->app->scoped(GatewayCallbackContext::class);

        $this->app->singleton('transaction.service', fn ($app) => $app->make(TransactionService::class));
        $this->app->singleton('payment.service', fn ($app) => $app->make(PaymentService::class));
        $this->app->singleton('ifinfo.service', fn ($app) => $app->make(IpInfoService::class));
    }

    /**
     * Bootstrap application services.
     * Loads configuration settings, sets up observers, and ensures security features.
     */
    public function boot(AppConfigService $appConfigService, InstallationManager $installer): void
    {
        $this->registerSafeTranslator();
        $this->registerFallbackMiddlewareAliases();

        // Model observers must register in every environment (including
        // tests) so model lifecycle hooks like AgentObserver::creating run.
        $this->configureObservers();
        $this->configureEventListeners();
        $this->configureRateLimiters();

        // The `getDefaultLocale` macro is consumed widely (Page::getLabelAttribute,
        // PageController::create, the page-create / page-edit hero blades, several
        // models). Register it in EVERY environment so route-level feature tests
        // don't crash with "Method ... ::getDefaultLocale does not exist". It's
        // pure config lookup, so there's no install-state coupling.
        if (! Application::hasMacro('getDefaultLocale')) {
            Application::macro('getDefaultLocale', function () {
                return config('app.default_language') ?? config('app.fallback_locale') ?? config('app.locale');
            });
        }

        if ($this->app->environment('testing')) {
            return;
        }

        if (! $installer->isInstalled()) {
            $this->ensureAppKey();

            return;
        }

        $this->ensureAppKey();

        // Console has its own way to migrate, and running one from inside the
        // boot of `php artisan migrate` would just race the command itself.
        if (! $this->app->runningInConsole()) {
            $installer->ensureSchemaMatchesCode();
        }

        $appConfigService->applyAppSettings();
        $appConfigService->applyMailSettings();

        // A queue worker boots once and then runs for hours. Mail Settings
        // saved in the admin panel after that never reached it, so every
        // queued email kept using the SMTP values from boot time and failed
        // against a host the administrator had already corrected. Re-read
        // them before each job; the reads are served from the settings
        // cache and cost nothing when nothing changed.
        if ($this->app->runningInConsole()) {
            Event::listen(JobProcessing::class, [RefreshMailSettingsBeforeJob::class, 'beforeJob']);
        }

        $appConfigService->forceHttpsIfEnabled();
        $appConfigService->applySmsConfig();
        $appConfigService->applyGoogleReCaptchaConfig();
        $appConfigService->ensureStorageSymlink();
    }

    /**
     * Ensure the application key is set.
     *
     * `.env` is created from `.env.example` earlier in bootstrap/app.php
     * (before Laravel loads env vars), so by the time we reach here the
     * file exists with `APP_KEY=` empty. We just need to generate a fresh
     * key on the very first request and update the runtime config so the
     * encrypter doesn't see `null`.
     *
     * `empty()` not `=== ''` — missing/blank env vars surface here as
     * `null`, which the strict-equality check would silently skip.
     */
    protected function ensureAppKey(): void
    {
        if (! empty(config('app.key'))) {
            return;
        }

        try {
            Artisan::call('key:generate', ['--force' => true]);
            Log::info('Application key generated successfully during deployment.');
        } catch (\Throwable $e) {
            Log::warning('key:generate failed during deployment: '.$e->getMessage());
        }
    }

    /**
     * Re-register route middleware aliases an upgraded installation may not have.
     *
     * Aliases are declared in `bootstrap/app.php`. A customer who applies an
     * update by uploading `app/` and `routes/` keeps the bootstrap file they
     * installed with, so a route naming an alias that older file never
     * declared resolves as a class name instead: the container throws
     * "Target class [<alias>] does not exist" and every route in the group
     * returns a 500 — the whole authenticated area, not one feature.
     *
     * This is a repair, never an override. An alias the running bootstrap
     * already declares is left exactly as that file set it, so a customised
     * mapping still wins.
     */
    public function registerFallbackMiddlewareAliases(): void
    {
        $router = $this->app['router'];

        $fallbacks = [
            'verified.when.required' => EnsureEmailIsVerifiedWhenRequired::class,
        ];

        foreach ($fallbacks as $alias => $middleware) {
            if (! array_key_exists($alias, $router->getMiddleware())) {
                $router->aliasMiddleware($alias, $middleware);
            }
        }
    }

    /**
     * Register model observers to handle model events.
     */
    protected function configureObservers(): void
    {
        User::observe(UserObserver::class);
        Merchant::observe(MerchantObserver::class);
        Agent::observe(AgentObserver::class);
    }

    /**
     * Register framework event listeners.
     */
    protected function configureEventListeners(): void
    {
        Event::listen(Verified::class, AwardSignupBonusOnVerified::class);

        /*
         * The Background Tasks panel only ever saw runs started from its own
         * Run button, so every row read "Never run" on a server whose cron was
         * fine. These two put the scheduler's ticks in the same log.
         */
        Event::listen(ScheduledTaskFinished::class, [RecordScheduledTaskRun::class, 'whenFinished']);
        Event::listen(ScheduledTaskFailed::class, [RecordScheduledTaskRun::class, 'whenFailed']);

        /*
         * A lockout is the one refusal DetectIpAbuse cannot see: the login
         * throttle answers with a validation redirect, not a status the
         * middleware recognises. It is also the clearest signal there is —
         * the limiter has already absorbed a run of wrong passwords before
         * it fires — so it carries the heaviest weight.
         */
        Event::listen(Lockout::class, function (Lockout $event): void {
            app(IpAbuseGuard::class)->record($event->request, 'login_lockout');
        });

        /*
         * Somebody who gets in was a customer who mistyped, not an intruder.
         * Forgetting their strikes is what keeps a fumbled sign-in followed
         * by a good one from ever accumulating towards a block.
         */
        Event::listen(Login::class, function (): void {
            app(IpAbuseGuard::class)->clear((string) request()->ip());
        });
    }

    protected function configureRateLimiters(): void
    {
        RateLimiter::for('remittance-api', fn (Request $request): Limit => Limit::perMinute(60)
            ->by((string) ($request->user()?->id ?? $request->ip())));

        RateLimiter::for('remittance-quotes', fn (Request $request): Limit => Limit::perMinute(20)
            ->by((string) ($request->user()?->id ?? $request->ip())));

        RateLimiter::for('remittance-sends', fn (Request $request): Limit => Limit::perMinute(5)
            ->by((string) ($request->user()?->id ?? $request->ip())));

        // Mobile API. Keyed on the token owner so one noisy device cannot
        // spend another customer's allowance, falling back to the address
        // only for the endpoints reachable before sign-in.
        //
        // Four tiers rather than one, because the cost of abuse differs:
        // guessing a password is cheap to attempt and expensive to get wrong,
        // while listing transactions is neither.
        RateLimiter::for('api-auth', fn (Request $request): Limit => Limit::perMinute(10)
            ->by((string) $request->ip()));

        RateLimiter::for('api-read', fn (Request $request): Limit => Limit::perMinute(120)
            ->by((string) ($request->user()?->id ?? $request->ip())));

        RateLimiter::for('api-write', fn (Request $request): Limit => Limit::perMinute(30)
            ->by((string) ($request->user()?->id ?? $request->ip())));

        RateLimiter::for('api-money', fn (Request $request): Limit => Limit::perMinute(10)
            ->by((string) ($request->user()?->id ?? $request->ip())));
    }
}
