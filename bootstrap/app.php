<?php

/*
|--------------------------------------------------------------------------
| First-deploy .env bootstrap
|--------------------------------------------------------------------------
|
| Runs BEFORE Laravel's LoadEnvironmentVariables bootstrapper so the
| values from the shipped .env.example are visible to env() from the
| very first request.
|
| Without this, the first request after a fresh CodeCanyon unzip reads
| env defaults (APP_NAME=laravel) → session cookie name is computed as
| `laravel_session` → AppServiceProvider then creates .env →
| second request reads the real APP_NAME → cookie name flips to
| `digikash_..._session` → browser's old `laravel_session` cookie is
| invisible → session is lost → fresh CSRF token → "CSRF token mismatch".
*/
if (! file_exists(__DIR__.'/../.env') && file_exists(__DIR__.'/../.env.example')) {
    @copy(__DIR__.'/../.env.example', __DIR__.'/../.env');
}

use App\Console\Commands\AlertStuckRemittanceTransfers;
use App\Console\Commands\BuildReleaseCommand;
use App\Console\Commands\CheckRemittancePayoutProvider;
use App\Console\Commands\CheckRemittanceReadiness;
use App\Console\Commands\ClearApp;
use App\Console\Commands\DispatchPendingRemittances;
use App\Console\Commands\ExpireP2POfferPromotions;
use App\Console\Commands\ExpireP2POrders;
use App\Console\Commands\MakeBackendController;
use App\Console\Commands\OptimizeApp;
use App\Console\Commands\ProcessSubscriptions;
use App\Console\Commands\ProcessWalletEarnRewards;
use App\Console\Commands\PruneObservabilityData;
use App\Console\Commands\ReconcileRemittanceTransfers;
use App\Console\Commands\RefreshExchangeRates;
use App\Console\Commands\SyncPendingVirtualCards;
use App\Console\Commands\SyncUserFeatures;
use App\Console\Commands\VerifyRemittanceUatTransfer;
use App\Http\Controllers\Frontend\PwaController;
use App\Http\Middleware\ApplyThemePreview;
use App\Http\Middleware\AuthenticateMiddleware;
use App\Http\Middleware\BlockIp;
use App\Http\Middleware\CheckUserFeature;
use App\Http\Middleware\CheckUserStatus;
use App\Http\Middleware\DemoMode;
use App\Http\Middleware\DetectIpAbuse;
use App\Http\Middleware\EnforceMinimumAppVersion;
use App\Http\Middleware\EnsureAdminFeatureEnabled;
use App\Http\Middleware\EnsureApiIdempotency;
use App\Http\Middleware\EnsureApiRole;
use App\Http\Middleware\EnsureApplicationInstalled;
use App\Http\Middleware\EnsureEmailIsVerifiedWhenRequired;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureKYCVerified;
use App\Http\Middleware\EnsureMerchantApiIdempotency;
use App\Http\Middleware\EnsureP2PCountryAllowed;
use App\Http\Middleware\EnsureP2PEnabled;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureTwoFactorAuthenticated;
use App\Http\Middleware\FilterBoostBrowserLoggerScript;
use App\Http\Middleware\HandleReferralLinks;
use App\Http\Middleware\LockScreen;
use App\Http\Middleware\MaintenanceMode;
use App\Http\Middleware\MerchantApiAuth;
use App\Http\Middleware\PreventDuplicateSubmission;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\ResolveMerchantFromToken;
use App\Http\Middleware\SecureHeaders;
use App\Http\Middleware\SetApiLocale;
use App\Http\Middleware\SetPaginationView;
use App\Http\Middleware\TrackUserPresence;
use App\Http\Middleware\Translate;
use App\Http\Middleware\UseApiErrorEnvelope;
use App\Http\Middleware\XSS;
use App\Models\ApiIdempotencyKey;
use App\Models\BackgroundTaskLog;
use App\Models\LoginOtpCode;
use App\Models\MerchantApiIdempotency;
use App\Models\Setting;
use App\Services\BackgroundHealthService;
use App\Services\Observability\ErrorCollector;
use App\Support\AdminShell;
use App\Support\ApiExceptionRenderer;
use App\Support\FrontendAuthRoutes;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;
use JoeDixon\Translation\Console\Commands\SynchroniseMissingTranslationKeys;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // PWA endpoints intentionally have NO session / CSRF middleware. A new
            // session per manifest or service-worker fetch would attach Set-Cookie
            // headers and force Cache-Control: private, which interferes with
            // browser PWA install detection and bloats session storage.
            Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
            Route::get('/service-worker.js', [PwaController::class, 'serviceWorker'])->name('pwa.service-worker');
            Route::get('/offline', [PwaController::class, 'offline'])->name('pwa.offline');
            Route::get('/launch', [PwaController::class, 'launcher'])->name('pwa.launcher');
            Route::get('/install-app', [PwaController::class, 'install'])->name('pwa.install');

            // The legacy web installer (/install, the wizard, and its JSON
            // test endpoints) was removed in Milestone 1. Installation is a
            // CLI operation: `php artisan makinpay:install`. EnsureApplicationInstalled
            // answers uninstalled requests with a 503 maintenance page.

            // theme.preview only on the public groups: the admin page builder
            // saves against activeTheme(), so it must never see a preview.
            Route::middleware(['web', 'theme.preview'])
                ->group(base_path('routes/auth.php'));

            Route::middleware(['web', 'auth:admin', 'verified', 'XSS', 'lock_screen', '2fa', 'demo'])
                ->group(base_path('routes/admin.php'));

            Route::middleware(['web', 'theme.preview'])
                ->group(base_path('routes/web.php'));

            Route::middleware('api')
                ->prefix('api')
                ->name('api.')
                ->group(base_path('routes/api.php'));
        }
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Most installations sit behind a TLS-terminating proxy (Cloudflare,
        // nginx, a shared host's load balancer) that speaks plain HTTP to PHP.
        // Without this the app saw every request as http://, so a signed
        // verification link generated as https:// failed its signature check
        // on the click, and password-reset links were built with the wrong
        // scheme. Only the scheme and port headers are trusted. The host
        // header is not: with every proxy trusted, a client-supplied
        // X-Forwarded-Host would become the host of every mailed link, so a
        // password-reset request could be made to deliver a link pointing at
        // an attacker's domain. The client-IP header is not trusted either, so
        // a caller that is not behind a proxy still cannot forge the address
        // used for rate limiting and logs.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PORT,
        );

        $middleware->redirectGuestsTo(fn (Request $request): string => FrontendAuthRoutes::loginUrlFor($request));
        $middleware->redirectUsersTo(fn (Request $request): string => FrontendAuthRoutes::authenticatedUrlFor($request));

        // The sidebar's own remembered state, written by admin-shell.js so PHP
        // can paint the column at the right width on the first frame. It was
        // written in the clear and read back through the cookie encrypter,
        // which could not decrypt it and so discarded it on every request: the
        // server always rendered the column expanded with only the current
        // page's group open, the browser painted that, and the script then
        // collapsed the column and reopened the operator's groups a frame
        // later. That correction is the jump seen on every page load and every
        // menu click, and this cookie exists precisely to stop it. Nothing
        // here is a credential - it is {collapsed, groups} of booleans - and
        // AdminShell::state() already treats the value as hostile: bounded
        // JSON depth, a caught decode failure, and per-key validation before
        // any of it is believed.
        $middleware->encryptCookies(except: [
            AdminShell::STATE_COOKIE,
        ]);

        $middleware->validateCsrfTokens(except: [
            'ipn/*',
            'webhooks/kyc/*',
            // A bill payment provider is not logged in either: its driver
            // authenticates the notice, which only triggers a status check.
            'webhooks/bill-payments/*',
            // Signed URLs, not sessions: the provider posting here is not
            // logged in, so the signature is what authorises the callback.
            'webhooks/observability/*',
        ]);
        $middleware->append([
            SecureHeaders::class,

        ]);

        // Apply EnsureFrontendRequestsAreStateful only to API routes
        $middleware->appendToGroup('api', [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        // BlockIp before anything the request came for, so a denied address is
        // turned away at the door rather than after the work is done.
        // DetectIpAbuse sits directly inside it: it counts the refusals the
        // rest of the stack produces, and being inside BlockIp means an
        // address that is already blocked cannot keep striking itself.
        $middleware->prependToGroup('api', [
            EnsureApplicationInstalled::class,
            BlockIp::class,
            DetectIpAbuse::class,
        ]);

        $middleware->appendToGroup('web', [
            FilterBoostBrowserLoggerScript::class,
            MaintenanceMode::class,
            SetPaginationView::class,
            HandleReferralLinks::class,
            TrackUserPresence::class,
            Translate::class,

        ]);

        $middleware->prependToGroup('web', [
            EnsureApplicationInstalled::class,
            BlockIp::class,
            DetectIpAbuse::class,
        ]);

        $middleware->alias([
            'auth'                 => AuthenticateMiddleware::class,
            'auth.session'         => AuthenticateSession::class,
            'account.status.check' => CheckUserStatus::class,
            'merchant.auth'        => MerchantApiAuth::class,
            'merchant.idempotency' => EnsureMerchantApiIdempotency::class,
            'kyc.verified'         => EnsureKYCVerified::class,
            'guest'                => RedirectIfAuthenticated::class,
            'role'                 => RoleMiddleware::class,
            'permission'           => PermissionMiddleware::class,
            'role_or_permission'   => RoleOrPermissionMiddleware::class,
            'XSS'                  => XSS::class,
            'block.ip'             => BlockIp::class,
            'lock_screen'          => LockScreen::class,
            '2fa'                  => EnsureTwoFactorAuthenticated::class,
            'prevent.duplicate'    => PreventDuplicateSubmission::class,
            'feature'              => CheckUserFeature::class,
            'feature.enabled'      => EnsureFeatureEnabled::class,
            'admin.feature'        => EnsureAdminFeatureEnabled::class,
            'theme.preview'        => ApplyThemePreview::class,
            'p2p.enabled'          => EnsureP2PEnabled::class,
            'p2p.country'          => EnsureP2PCountryAllowed::class,
            'demo'                 => DemoMode::class,
            // Opts a route group into the mobile API error envelope. Marker
            // only: it sets a request attribute the exception handler reads,
            // so the merchant server-to-server API sharing the api/v1 prefix
            // keeps its own {error, message} body.
            UseApiErrorEnvelope::ALIAS => UseApiErrorEnvelope::class,
            // Restricts a mobile route to a User::role, which is a different
            // question from the Spatie role the admin panel asks about.
            'api.role' => EnsureApiRole::class,
            // Replay protection for anything the app does that moves money.
            'api.idempotency' => EnsureApiIdempotency::class,
            // Lets a Sanctum token stand in for the merchant API key headers,
            // so the app never has to hold those keys.
            'api.merchant' => ResolveMerchantFromToken::class,
            // Reply language for a token client, which has no session for
            // Translate to read.
            'api.locale' => SetApiLocale::class,
            // Turns away an app build the operator no longer supports. Blank
            // floor by default, so it blocks nobody until an admin sets one.
            EnforceMinimumAppVersion::ALIAS => EnforceMinimumAppVersion::class,
            // Laravel's `verified`, gated by the Security Settings toggle so
            // enabling email verification stays an administrator's decision.
            'verified.when.required' => EnsureEmailIsVerifiedWhenRequired::class,
            // Holds a user on the change-password screen after an admin set
            // a temporary password with "require change at next sign-in".
            'password.change.required' => EnsurePasswordChanged::class,
        ]);
    })
    ->withCommands([
        SynchroniseMissingTranslationKeys::class,
        BuildReleaseCommand::class,
        ClearApp::class,
        CheckRemittancePayoutProvider::class,
        CheckRemittanceReadiness::class,
        DispatchPendingRemittances::class,
        OptimizeApp::class,
        SyncUserFeatures::class,
        MakeBackendController::class,
        ExpireP2POrders::class,
        ExpireP2POfferPromotions::class,
        ProcessWalletEarnRewards::class,
        ProcessSubscriptions::class,
        PruneObservabilityData::class,
        ReconcileRemittanceTransfers::class,
        SyncPendingVirtualCards::class,
        AlertStuckRemittanceTransfers::class,
        VerifyRemittanceUatTransfer::class,
        RefreshExchangeRates::class,
    ])
    ->withSchedule(function (Schedule $schedule) {
        // Proof that cron is calling `schedule:run` at all. Everything below
        // is invisible when it is not, so the admin dashboard reads this
        // heartbeat to tell an administrator their background work is dead
        // instead of failing silently.
        $schedule->call(fn () => app(BackgroundHealthService::class)->recordSchedulerHeartbeat())
            ->everyMinute()
            ->name('background-health-heartbeat')
            ->withoutOverlapping();

        // Auto-expire P2P orders
        $schedule->command('p2p:orders:expire')->everyMinute();

        // Last month's wallet statements, to customers who asked for them in the app.
        $schedule->command('statements:email')->monthlyOn(1, '06:00');

        // Timed account suspensions set from Admin → Users → Manage. Sign-in
        // is already refused only while the window is open; this clears the
        // lapsed window so the admin screens stop showing it.
        $schedule->command('users:lift-expired-suspensions')->everyFifteenMinutes()->withoutOverlapping(10);

        /* Sign-in history retention. Nightly and off-peak: it deletes in batches. */
        $schedule->command('login-activities:prune')->dailyAt('03:20')->withoutOverlapping(30);

        $schedule->command('p2p:promotions:expire')->everyMinute();

        $schedule->command('wallet-earn:process')->everyMinute();

        $schedule->command('subscription:process --renewals')->hourly();
        $schedule->command('merchant-subscriptions:process --limit=100')->everyMinute()->withoutOverlapping(10);
        $schedule->command('model:prune', [
            // ApiIdempotencyKey joined this list late: it was added after the
            // merchant one and the pruning was not copied across, so its rows
            // - each holding the full JSON answer to a money call - were kept
            // for ever. A model with prunable() and no schedule entry prunes
            // nothing at all.
            '--model' => [MerchantApiIdempotency::class, ApiIdempotencyKey::class, BackgroundTaskLog::class, LoginOtpCode::class],
        ])->daily()->withoutOverlapping();

        $schedule->command('remittance:dispatch-pending')->everyMinute()->withoutOverlapping();

        // A gift card bought for a future date is paid for at purchase but
        // holds its email until the scheduled moment. Without this tick the
        // sender is debited and the card is never delivered at all.
        $schedule->command('gift-card:dispatch-scheduled')->everyMinute()->withoutOverlapping();

        $schedule->command('remittance:reconcile')->everyFiveMinutes()->withoutOverlapping(10);

        // Bill payments sent to a provider: refund the ones never sent, record
        // the dispatches that lost their answer, and ask the provider about
        // the ones still pending. It never sends a payment again, and a
        // payment the provider never resolves goes to review, not a refund.
        $schedule->command('bill-payment:reconcile')->everyFiveMinutes()->withoutOverlapping(10);

        // BP4: the biller catalogue sync for the providers whose admin
        // switched the daily sync on (off by default). It only queues the
        // sync job; the queue runs it, and the admins are told what changed.
        $schedule->command('bill-payment:auto-sync-billers')->dailyAt('04:10')->withoutOverlapping(30);

        // Mass payments: run a batch nobody is working (no queue worker, or a
        // job that died) straight from cron, and finalize one whose last row
        // landed without the close. Shares the processor's per-batch lock
        // with the job, and every row is idempotent, so overlap pays nothing
        // twice. The prune expires stale drafts and deletes old ones that
        // never held money.
        $schedule->command('mass-payments:resume')->everyMinute()->withoutOverlapping(10);
        $schedule->command('mass-payments:prune')->dailyAt('03:40')->withoutOverlapping(30);

        // Mass payouts already handed to a provider: ask it, read-only, about
        // the lines still waiting, and settle or refund them on its answer.
        // Never sends a line again, and a line left unanswered goes to review,
        // not a refund.
        $schedule->command('mass-payouts:reconcile')->everyMinute()->withoutOverlapping(10);

        /*
         * The same idea for KYC. Every driver implements checkStatus() and
         * nothing called it, so an automated verification whose webhook was
         * lost — or refused on a signature, or delivered while the worker was
         * down — stayed PENDING for good and only the applicant noticed.
         * Ten minutes rather than five: a submission is not even eligible
         * until it has sat for half an hour, so asking more often buys
         * nothing.
         */
        $schedule->command('kyc:reconcile-pending')->everyTenMinutes()->withoutOverlapping(10);

        $schedule->command('remittance:alert-stuck')->everyTenMinutes()->withoutOverlapping(10);

        // A queue that goes past its SLA overnight is exactly the one nobody
        // has the dashboard open for. Silent unless an administrator switches
        // SLA alerts on, and deduplicated per queue so a backlog nobody can
        // clear today does not alert on every tick.
        $schedule->command('dashboard:alert-sla')->everyTenMinutes()->withoutOverlapping(10);

        // Finish the card top-ups that did not finish themselves: compensate
        // the ones the provider was never sent, settle the ones it confirmed
        // while the local write was failing, and flag the rest.
        //
        // It never auto-refunds an outcome that might have succeeded at the
        // provider — an attempt it cannot resolve stays in `needs_review` for
        // an operator. The companion `virtual-card:report-stranded-topups` is
        // deliberately NOT scheduled: it reads historical rows that predate
        // the attempt record, and real provider money sits behind them.
        $schedule->command('virtual-card:reconcile-topups')->everyFiveMinutes()->withoutOverlapping(10);

        // Providers issue asynchronously and announce the finished card by
        // webhook. When that webhook was never configured, or never arrives,
        // this asks the provider directly so a card does not stay `pending`
        // — unfundable and unusable — for good.
        $schedule->command('virtual-card:sync-pending-cards')->everyFiveMinutes()->withoutOverlapping(10);
        $schedule->command('virtual-card:sync-activity')->everyThirtyMinutes()->withoutOverlapping(10);
        $schedule->command('virtual-card:unfreeze-due')->everyFifteenMinutes()->withoutOverlapping(5);

        // Live rates are pulled here rather than lazily inside an accessor, so
        // the fetch stays off the request path and `rate_updated_at` measures
        // the feed instead of the traffic. Fifteen minutes sits inside the
        // 1440-minute default staleness window with room for a few failures.
        $schedule->command('currency:refresh-rates')->everyFifteenMinutes()->withoutOverlapping(10);
        // Two minutes behind the refresh, so an alert is judged on the rate
        // that run wrote rather than the one before it.
        $schedule->command('rate-alerts:check')->cron('2,17,32,47 * * * *')->withoutOverlapping(10);
        $schedule->command('requests:remind-due')->dailyAt('09:00')->withoutOverlapping(10);
        // BP3: "Pay <bill>" on the day of the month the customer asked for,
        // once a month per saved bill. Nothing is paid by itself.
        $schedule->command('bill-payment:remind-saved')->dailyAt('09:00')->withoutOverlapping(10);

        // Round 7: budgets warn once per period per level; vaults top
        // themselves up early in the morning; scheduled payments run at
        // their moment and are reminded of the day before.
        $schedule->command('budgets:check')->hourly()->withoutOverlapping(10);
        $schedule->command('vaults:auto-save')->dailyAt('06:05')->withoutOverlapping(10);
        $schedule->command('schedules:run')->everyFiveMinutes()->withoutOverlapping(10);
        $schedule->command('schedules:remind')->hourly()->withoutOverlapping(10);
        // Round 8: a saved P2P market search is checked a little behind the
        // market's own pace.
        $schedule->command('p2p:market-alerts')->everyFifteenMinutes()->withoutOverlapping(10);
        $schedule->command('p2p:reprice')->everyFiveMinutes()->withoutOverlapping(10);

        // Nightly ledger reconciliation. Read-only: the audit never writes, it
        // reports rows the old 2-decimal ledger truncated, wallets that have
        // drifted from their entries, unusable exchange rates and method rates
        // that have diverged from the currency they settle in.
        //
        // `--notify` is what makes an unattended run worth scheduling — cron
        // discards stdout, so a silent report is no report. It stays quiet on a
        // clean ledger and alerts currency administrators otherwise,
        // deduplicated by finding set.
        //
        // Runs after currency:refresh-rates has had the night to settle, so a
        // rate that simply had not been fetched yet is not reported as stale.
        //
        // The two repair commands — `money:reconcile-method-rates` and
        // `money:fix-method-limits` — are deliberately NOT scheduled. Both
        // write to money configuration behind an `--apply` flag, and both only
        // report what this audit already covers. They are what an operator runs
        // *after* reading the alert, having decided the change is right.
        $schedule->command('money:audit-precision --notify')->dailyAt('03:30')->withoutOverlapping(30);

        // Envato licensing and its heartbeat were removed in Milestone 1.
        // The project updater keeps its own signature-verified release check,
        // which no longer depends on a per-domain activation state.

        // Retention pruning for captured errors. Nightly, bounded, and
        // deliberately unhurried: it clears what it can inside its ceiling
        // and leaves the rest for tomorrow rather than locking the error
        // table on a shared host.
        $schedule->command('observability:prune')->dailyAt('04:10')->withoutOverlapping(30);

        $schedule->call(function () {
            $disk   = Storage::disk('public');
            $folder = 'images/temp/'.now()->subDay()->format('Y/m/d');

            if ($disk->exists($folder)) {
                $disk->deleteDirectory($folder);
                logger()->info("Summernote temp folder deleted: {$folder}");
            } else {
                logger()->info("No summernote temp folder found for deletion: {$folder}");
            }
        })->dailyAt('02:00');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Every uncaught throwable is offered to the observability
        // collector before Laravel logs it. The reporter returns nothing,
        // so the framework still writes its own log line — this augments
        // the existing channel rather than replacing it.
        //
        // report() is the swallowing entry point. A collector that threw
        // here would replace a real exception with its own and take down
        // the request it was only meant to observe.
        $exceptions->report(function (Throwable $throwable): void {
            app(ErrorCollector::class)->report($throwable);
        });

        // Mobile API replies carry {message, code, errors} so the Flutter
        // client can branch on a stable code instead of a translated
        // sentence. The renderer returns null for every request that did not
        // pass through UseApiErrorEnvelope, which leaves the web panel and the
        // merchant server-to-server API rendering exactly as they do now.
        //
        // Registered after report() above, so observability still sees the
        // throwable: rendering a response does not suppress reporting.
        $exceptions->render(fn (Throwable $throwable, Request $request) => ApiExceptionRenderer::render($throwable, $request));

        // Secret settings (the SMTP password and any other `password`
        // element) are filled from storage before validation so a blank
        // field keeps the saved value. A failed save must not flash that
        // value back as old input.
        $exceptions->dontFlash(Setting::secretKeys());
    })->create();
