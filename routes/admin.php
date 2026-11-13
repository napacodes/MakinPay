<?php

use App\Http\Controllers\Backend\ActivityController;
use App\Http\Controllers\Backend\AddonController;
use App\Http\Controllers\Backend\AdminController;
use App\Http\Controllers\Backend\AdminNoticeController;
use App\Http\Controllers\Backend\AgentCommissionRuleController;
use App\Http\Controllers\Backend\AgentController;
use App\Http\Controllers\Backend\AppController;
use App\Http\Controllers\Backend\BackgroundTaskController;
use App\Http\Controllers\Backend\BillPayment\BillCategoryController;
use App\Http\Controllers\Backend\BillPayment\BillerController;
use App\Http\Controllers\Backend\BillPayment\BillerImportController;
use App\Http\Controllers\Backend\BillPayment\BillPaymentController;
use App\Http\Controllers\Backend\BillPayment\BillPaymentProviderController;
use App\Http\Controllers\Backend\BlogCategoryController;
use App\Http\Controllers\Backend\BlogController;
use App\Http\Controllers\Backend\CardholdersController;
use App\Http\Controllers\Backend\CurrencyController;
use App\Http\Controllers\Backend\CustomLandingController;
use App\Http\Controllers\Backend\DashboardController;
use App\Http\Controllers\Backend\DepositController;
use App\Http\Controllers\Backend\DepositMethodController;
use App\Http\Controllers\Backend\FeatureManagementController;
use App\Http\Controllers\Backend\FooterItemController;
use App\Http\Controllers\Backend\FooterSectionController;
use App\Http\Controllers\Backend\GiftCardController as AdminGiftCardController;
use App\Http\Controllers\Backend\GiftCardTemplateController;
use App\Http\Controllers\Backend\GlobalSearchController;
use App\Http\Controllers\Backend\IpAccessController;
use App\Http\Controllers\Backend\KycController;
use App\Http\Controllers\Backend\KycProviderController;
use App\Http\Controllers\Backend\KycTemplateController;
use App\Http\Controllers\Backend\LanguageController;
use App\Http\Controllers\Backend\Marketplace\CommissionAccountController as MarketplaceCommissionAccountController;
use App\Http\Controllers\Backend\Marketplace\MarketplaceSettingController;
use App\Http\Controllers\Backend\Marketplace\OrderController as MarketplaceOrderController;
use App\Http\Controllers\Backend\Marketplace\VendorController as MarketplaceVendorController;
use App\Http\Controllers\Backend\MassPayment\MassPaymentController;
use App\Http\Controllers\Backend\MassPayment\MassPayoutController;
use App\Http\Controllers\Backend\MediaAssetController;
use App\Http\Controllers\Backend\MediaStorageProviderController;
use App\Http\Controllers\Backend\MerchantController;
use App\Http\Controllers\Backend\MobileRechargeController;
use App\Http\Controllers\Backend\MobileRechargeProviderController;
use App\Http\Controllers\Backend\NavigationController;
use App\Http\Controllers\Backend\NotificationController;
use App\Http\Controllers\Backend\NotificationTemplateController;
use App\Http\Controllers\Backend\ObservabilityController;
use App\Http\Controllers\Backend\P2P\P2PAdminController;
use App\Http\Controllers\Backend\P2P\P2PAdvertiserController;
use App\Http\Controllers\Backend\P2P\P2PAssetFeeController;
use App\Http\Controllers\Backend\P2P\P2PDisputeController;
use App\Http\Controllers\Backend\P2P\P2POfferPromotionController;
use App\Http\Controllers\Backend\P2P\P2PPaymentAccountReviewController;
use App\Http\Controllers\Backend\P2P\P2PPaymentMethodController;
use App\Http\Controllers\Backend\P2P\P2PPromotionPackageController;
use App\Http\Controllers\Backend\P2P\P2PSettingController;
use App\Http\Controllers\Backend\PageComponentController;
use App\Http\Controllers\Backend\PageComponentRepeatedContentController;
use App\Http\Controllers\Backend\PageController;
use App\Http\Controllers\Backend\PaymentGatewayController;
use App\Http\Controllers\Backend\PaymentLinkController;
use App\Http\Controllers\Backend\PluginController;
use App\Http\Controllers\Backend\ProjectUpdaterController;
use App\Http\Controllers\Backend\QueueManagementController;
use App\Http\Controllers\Backend\ReferralController;
use App\Http\Controllers\Backend\RemittanceCorridorController;
use App\Http\Controllers\Backend\RemittanceTransferController;
use App\Http\Controllers\Backend\RoleController;
use App\Http\Controllers\Backend\SettingController;
use App\Http\Controllers\Backend\SiteSeoController;
use App\Http\Controllers\Backend\SocialController;
use App\Http\Controllers\Backend\StaffController;
use App\Http\Controllers\Backend\SubscriberController;
use App\Http\Controllers\Backend\Subscription\AdminUserSubscriptionController;
use App\Http\Controllers\Backend\Subscription\SubscriptionPlanController;
use App\Http\Controllers\Backend\SupportCategoryController;
use App\Http\Controllers\Backend\TaxRuleController;
use App\Http\Controllers\Backend\ThemeManagerController;
use App\Http\Controllers\Backend\TicketController;
use App\Http\Controllers\Backend\TransactionController;
use App\Http\Controllers\Backend\UserController;
use App\Http\Controllers\Backend\UserManageController;
use App\Http\Controllers\Backend\UserRankController;
use App\Http\Controllers\Backend\VirtualCardController;
use App\Http\Controllers\Backend\VirtualCardFeeSettingController;
use App\Http\Controllers\Backend\WalletEarnPlanController;
use App\Http\Controllers\Backend\WalletEarnStakeController;
use App\Http\Controllers\Backend\WithdrawController;
use App\Http\Controllers\Backend\WithdrawMethodController;
use App\Http\Controllers\Backend\WithdrawScheduleController;
use Illuminate\Support\Facades\Route;

$adminPrefix = trim((string) setting('admin_prefix', 'admin')) ?: 'admin';

/*
|--------------------------------------------------------------------------
| Admin/Backend Routes
|--------------------------------------------------------------------------
|
| Here is where you can register admin routes for your application. These
| routes are loaded by the bootstrap/app within a group which
| contains the "web,admin" middleware group. Now create something great!
|
*/

Route::prefix($adminPrefix)->as('admin.')->group(function () {

    // ========================== 🌟 Dashboard =============================
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    /*
     * The dashboard panels that refresh on their own. Each is gated on the
     * permission of the panel it feeds, so a role that cannot see a panel
     * cannot poll its data either — the endpoints are not a way around the
     * @can that hides the markup.
     */
    Route::prefix('dashboard')->as('dashboard.')->controller(DashboardController::class)->group(function () {
        Route::get('live', 'live')->name('live')->middleware('can:dashboard-queue');
        Route::get('gateway-health', 'gatewayHealth')->name('gateway-health')->middleware('can:system-health');
        Route::put('sla', 'updateSla')->name('sla.update')->middleware('can:dashboard-sla-manage');
    });

    // ========================== 🐞 Observability =============================
    // Read is gated on observability-list, triage and channel configuration on
    // observability-manage, and export on observability-export — the same split
    // the sidebar entries use, so a menu item is never visible to somebody the
    // route would refuse.
    Route::prefix('observability')->as('observability.')->controller(ObservabilityController::class)->group(function () {
        Route::get('/', 'issues')->name('issues')->middleware('can:observability-list');
        Route::get('issues/{group}', 'show')->name('issues.show')->middleware('can:observability-list');
        Route::get('error-codes', 'codes')->name('codes')->middleware('can:observability-list');
        Route::get('channels', 'channels')->name('channels')->middleware('can:observability-manage');

        Route::post('issues/bulk', 'bulk')->name('issues.bulk')->middleware('can:observability-manage');
        Route::post('issues/{group}/resolve', 'resolve')->name('issues.resolve')->middleware('can:observability-manage');
        Route::post('issues/{group}/ignore', 'ignore')->name('issues.ignore')->middleware('can:observability-manage');
        Route::post('issues/{group}/assign', 'assign')->name('issues.assign')->middleware('can:observability-manage');
        Route::get('issues/{group}/export', 'export')->name('issues.export')->middleware('can:observability-export');

        Route::post('channels/{key}/connect', 'connectChannel')->name('channels.connect')->middleware('can:observability-manage');
        Route::post('channels/test-all', 'testAllChannels')->name('channels.test-all')->middleware('can:observability-manage');
        Route::post('channels/{key}/test', 'testChannel')->name('channels.test')->middleware('can:observability-manage');
        Route::post('channels/{key}/disconnect', 'disconnectChannel')->name('channels.disconnect')->middleware('can:observability-manage');
    });

    // ========================== 🎁 Gift Cards =============================
    Route::prefix('gift-card-templates')->as('gift-card-templates.')->controller(GiftCardTemplateController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:gift-card-template-list');
        Route::post('/', 'store')->name('store')->middleware('can:gift-card-template-manage');
        Route::post('position-update', 'positionUpdate')->name('position-update')->middleware('can:gift-card-template-manage');
        Route::put('{template}', 'update')->name('update')->middleware('can:gift-card-template-manage');
        Route::delete('{template}', 'destroy')->name('destroy')->middleware('can:gift-card-template-manage');
    });
    Route::prefix('gift-cards')->as('gift-cards.')->controller(AdminGiftCardController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('can:gift-card-list');
        Route::post('{giftCard}/cancel', 'cancel')->name('cancel')->middleware('can:gift-card-manage');
    });

    // ========================== 👥 User Management ==========================
    Route::prefix('user')->as('user.')->controller(UserManageController::class)->group(function () {

        // 🔹 User Actions (GET)
        Route::get('manage/{username}/{param?}', 'manageUser')->name('manage');
        Route::get('login/{id}', 'loginAsUser')->name('login');
        Route::get('mail-send/all', 'mailSendAll')->name('mail-send.all');

        // 🔹 User Updates (POST)
        Route::post('feature-status/update', 'updateFeatureStatus')->name('feature-status.update');
        Route::post('update-balance', 'updateBalance')->name('update-balance');
        Route::post('status-update/{id}', 'statusUpdate')->name('status-update');
        Route::post('password-update/{id}', 'passwordUpdate')->name('password-update');
        Route::post('mail-send', 'mailSend')->name('mail-send');

        // 🔹 Account controls from the Manage page's modals
        Route::post('{id}/suspend', 'suspend')->name('suspend');
        Route::post('{id}/lift-suspension', 'liftSuspension')->name('lift-suspension');
        Route::post('{id}/reset-password', 'resetPassword')->name('reset-password');
        Route::post('{id}/sign-out-all', 'signOutAll')->name('sign-out-all');

        // 🔹 User Info Update (PUT)
        Route::put('update-info/{id}', 'infoUpdate')->name('update-info');
    });

    // 🔹 User Listings (GET)
    Route::prefix('user')->as('user.')->controller(UserController::class)->group(function () {
        Route::get('active', 'activeUser')->name('active');
        Route::get('suspended', 'suspendedUser')->name('suspended');
        Route::get('unverified', 'unverifiedUser')->name('unverified');
        Route::get('kyc-unverified', 'kycUnverifiedUser')->name('kyc-unverified');
        Route::get('{id}/transaction-stats', 'transactionStats')->name('transaction-stats');
        Route::post('{id}/convert-to-merchant', 'convertToMerchant')->name('convert-to-merchant');
        Route::post('{id}/convert-to-agent', 'convertToAgent')->name('convert-to-agent');
    });
    // 🔹 User Resources
    Route::resource('user', UserController::class)->except(['show', 'create', 'edit']);

    // =============================== 🏪 Merchant Management  =================================
    Route::prefix('merchant')->as('merchant.')->controller(MerchantController::class)->group(function () {
        Route::get('pending', 'pendingMerchant')->name('pending');
        Route::get('approved', 'approvedMerchant')->name('approved');
        Route::get('rejected', 'rejectedMerchant')->name('rejected');
        // Ahead of Route::resource's merchant/{merchant}, or that would swallow it.
        Route::get('review/{merchant}', 'review')->name('review');
        Route::post('request-action', 'merchantAction')->name('request-action');
    });
    // 🔹 Merchant Resources
    Route::resource('merchant', MerchantController::class);

    // =============================== 🧑‍💼 Agent Management  =================================
    Route::prefix('agent')->as('agent.')->controller(AgentController::class)->group(function () {
        Route::get('pending', 'pendingAgent')->name('pending');
        Route::get('approved', 'approvedAgent')->name('approved');
        Route::get('rejected', 'rejectedAgent')->name('rejected');
        Route::post('request-action', 'agentAction')->name('request-action');
    });
    Route::resource('agent/commission-rules', AgentCommissionRuleController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->names('agent.commission-rules');
    // 🔹 Agent Resources
    Route::resource('agent', AgentController::class)->only(['index']);

    // ================================ 🔑 KYC Management   =================================
    Route::prefix('kyc')->as('kyc.')->group(function () {
        Route::controller(KycController::class)->group(function () {
            Route::get('pending', 'pending')->name('pending');
            Route::get('index', 'index')->name('index');
            Route::get('export', 'export')->name('export');
            Route::post('action', 'requestAction')->name('request-action');
            Route::get('review/{submission}', 'review')->name('review');
            /* The only way to an identity document. Numbers only: the second
               parameter is a position in the submission's own list, never a
               path, so there is nothing here to traverse. */
            Route::get('review/{submission}/document/{document}', 'document')
                ->whereNumber(['submission', 'document'])
                ->name('document');
        });
        Route::patch('template/{template}/toggle', [KycTemplateController::class, 'toggle'])->name('template.toggle');
        Route::resource('template', KycTemplateController::class)->except(['show', 'create']);
        Route::post('providers/test', [KycProviderController::class, 'test'])->name('providers.test');
        Route::resource('providers', KycProviderController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->parameters(['providers' => 'provider']);
        Route::post('providers/{provider}/activate', [KycProviderController::class, 'activate'])->name('providers.activate');
        Route::post('providers/{provider}/deactivate', [KycProviderController::class, 'deactivate'])->name('providers.deactivate');
    });

    // ================================ 🚩 Feature Management  =================================
    Route::prefix('features')->as('features.')->controller(FeatureManagementController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('{feature}/edit', 'edit')->name('edit');
        Route::put('{feature}', 'update')->name('update');
        Route::patch('{feature}/toggle', 'toggle')->name('toggle');

        Route::patch('addons/{slug}/status', [AddonController::class, 'status'])
            ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('addons.status');
        Route::post('addons/{slug}/license', [AddonController::class, 'license'])
            ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('addons.license');
        Route::post('addons/{slug}/license/refresh', [AddonController::class, 'refreshLicense'])
            ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('addons.license.refresh');
        Route::post('addons/packages', [AddonController::class, 'uploadPackage'])
            ->name('addons.packages.store');
        Route::post('addons/packages/{token}/check', [AddonController::class, 'recheckPackage'])
            ->where('token', '[A-Za-z0-9]{40}')
            ->name('addons.packages.check');
        Route::post('addons/packages/{token}/install', [AddonController::class, 'installPackage'])
            ->where('token', '[A-Za-z0-9]{40}')
            ->name('addons.packages.install');
        Route::delete('addons/packages/{token}', [AddonController::class, 'discardPackage'])
            ->where('token', '[A-Za-z0-9]{40}')
            ->name('addons.packages.discard');
        Route::post('addons/{slug}/install', [AddonController::class, 'install'])
            ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('addons.install');
        Route::post('addons/{slug}/features', [AddonController::class, 'features'])
            ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('addons.features');
        Route::delete('addons/{slug}', [AddonController::class, 'uninstall'])
            ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('addons.destroy');
    });

    // ================================ 🔍 User/Merchant Activity History  =================================
    Route::get('activity-log', [ActivityController::class, 'index'])->name('activity-log');

    Route::controller(ActivityController::class)->prefix('activity-log')->as('activity-log.')->group(function () {
        /* Declared before the {activity} wildcard, or "export" is read as an id. */
        Route::get('export', 'export')->name('export');
        Route::post('sign-out-all', 'signOutAllDevices')->name('sign-out-all');
        Route::get('{activity}', 'show')->name('show')->whereNumber('activity');
        Route::post('{activity}/revoke', 'revoke')->name('revoke')->whereNumber('activity');
        Route::post('accounts/{user}/force-2fa', 'forceTwoFactor')->name('force-2fa')->whereNumber('user');
        Route::post('accounts/{user}/unblock', 'unblock')->name('unblock')->whereNumber('user');
    });

    /* The addresses BlockIp refuses. Edited here, enforced by the middleware. */
    Route::controller(IpAccessController::class)->prefix('ip-access')->as('ip-access.')->group(function () {
        Route::get('/', 'index')->name('index');
        /* The side panel. Keyed by ?ip= rather than a row id, because a
           held-back address is the block that was never written. */
        Route::get('panel', 'show')->name('panel');
        Route::post('/', 'store')->name('store');
        Route::delete('{ipAccess}', 'destroy')->name('destroy')->whereNumber('ipAccess');
    });

    // ================================ 🔐 Admin Profile  =================================
    Route::prefix('profile')->as('profile.')->controller(AdminController::class)->group(function () {
        Route::get('profile', 'profile')->name('view');
        Route::post('info-update', 'updateInfo')->name('info.update');
        Route::post('password-update', 'updatePassword')->name('password.update');

        // Two-Factor Authentication
        Route::prefix('2fa')->as('2fa.')->group(function () {
            Route::post('enable', 'enable2fa')->name('enable');
            Route::post('disable', 'disable2fa')->name('disable');
        });
    });

    // ======================== 👨‍💼 Staff Management  ==============================
    /* Declared before the resource so `staff/{id}/status` is never read as `staff/{staff}`. */
    Route::controller(StaffController::class)->prefix('staff')->as('staff.')->group(function () {
        Route::post('{id}/status', 'updateStatus')->name('status');
        Route::post('{id}/password-reset', 'sendPasswordReset')->name('password-reset');
        Route::post('{id}/revoke-sessions', 'revokeSessions')->name('revoke-sessions');
    });
    /* No `edit`: Manage is the one screen that edits a member, and it is a modal. */
    Route::resource('staff', StaffController::class)->except(['show', 'create', 'destroy', 'edit']);
    /*
     * Declared before the resource so `role/{id}/permissions` is never read as
     * `role/{role}`. That page is the role's own editor — its name and its
     * permissions are one decision, so one form — which is why the resource
     * has no `edit` of its own and the page saves through `role.update`.
     */
    Route::get('role/{id}/permissions', [RoleController::class, 'permissionEditor'])->name('role.permissions');
    /*
     * Renaming is its own endpoint, not `update`. The manage screen states the
     * role in its header rather than in a pair of fields, so the rename dialog
     * posts a name and a description and nothing else — and `update()` syncs
     * permissions from whatever arrives, which for that payload is none.
     */
    Route::put('role/{role}/rename', [RoleController::class, 'rename'])->name('role.rename');
    Route::resource('role', RoleController::class)->except(['edit']);

    // ======================== 💱 Currency Management  ==============================
    Route::get('currency/rates', [CurrencyController::class, 'rates'])->name('currency.rates');
    Route::resource('currency', CurrencyController::class);

    // ================================== 💳 Payment Gateway ===============================
    Route::prefix('payment')->as('payment.')->group(function () {
        Route::post('gateway/{gateway}/test', [PaymentGatewayController::class, 'test'])->name('gateway.test');
        Route::resource('gateway', PaymentGatewayController::class)->only(['index', 'edit', 'update']);
        Route::get('gateway-currency/{gateway_id}', [PaymentGatewayController::class, 'gatewayCurrency'])->name('gateway-currency');
    });

    // ======================== 💳 Virtual Card Management  ===============================
    Route::prefix('virtual-card')->name('virtual-card.')->controller(VirtualCardController::class)->group(function () {
        // Card Requests
        Route::prefix('requests')->name('requests.')->group(function () {
            Route::get('awaiting', 'requestAwaiting')->name('awaiting');
            Route::get('all', 'requestAll')->name('all');
            Route::post('{uuid}/review', 'review')->name('review');
        });

        // Cardholder management routes
        Route::prefix('cardholders')->name('cardholders.')->controller(CardholdersController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/{id}/action', 'action')->name('action');
            // Pre-index a cardholder with the Bitnob Visa BIN pool —
            // call this once per cardholder before issuance to avoid
            // the "User with this email is not indexed for visa" error.
            Route::post('/{id}/bitnob-verify', 'bitnobVerify')->name('bitnob-verify');
        });

        // Card Management
        Route::get('list', 'virtualCardList')->name('list');
        Route::post('update-status', 'statusUpdate')->name('update-status');
        // Re-fetch a card's live state from its gateway and persist
        // any changes (status / last4 / expiry). Provider-agnostic.
        Route::post('cards/{card}/refresh', 'refreshCard')->name('cards.refresh');

        // Provider Configuration — multi-gateway management hub.
        Route::prefix('provider')->name('provider.')->group(function () {
            Route::get('/', 'provider')->name('index');
            Route::get('manage/{id}', 'providerManage')->name('manage');
            Route::put('update/{provider}', 'providerUpdate')->name('update');
            // Per-provider drill-down: capability matrix, recent cards,
            // recent requests, diagnostic actions.
            Route::get('show/{id}', 'providerShow')->name('show');
            // JSON endpoint that runs the provider's auth probe so the
            // admin UI can show "credentials OK / failed" inline.
            Route::post('test-connection/{id}', 'providerTestConnection')->name('test-connection');
        });

        // Virtual Card Settings
        Route::resource('fee-settings', VirtualCardFeeSettingController::class)
            ->names('fee-settings');
    });

    // ======================== Wallet Earn Management ===============================
    Route::prefix('wallet-earn')->as('wallet-earn.')->middleware('admin.feature:wallet_earn')->group(function () {
        Route::get('/', [WalletEarnStakeController::class, 'index'])->name('index');
        Route::get('stakes/{stake}', [WalletEarnStakeController::class, 'show'])->name('stakes.show');
        Route::post('stakes/{stake}/approve', [WalletEarnStakeController::class, 'approve'])->name('stakes.approve');
        Route::post('stakes/{stake}/reject', [WalletEarnStakeController::class, 'reject'])->name('stakes.reject');
        Route::post('stakes/{stake}/cancel', [WalletEarnStakeController::class, 'cancel'])->name('stakes.cancel');
        Route::post('stakes/{stake}/complete', [WalletEarnStakeController::class, 'complete'])->name('stakes.complete');
        Route::post('plans/position-update', [WalletEarnPlanController::class, 'positionUpdate'])->name('plans.position-update');

        Route::resource('plans', WalletEarnPlanController::class)
            ->except('show')
            ->parameters(['plans' => 'plan']);
    });

    // ======================== 💰 Deposit Management  ===============================
    Route::prefix('deposit')->as('deposit.')->group(function () {
        Route::controller(DepositController::class)->group(function () {
            Route::get('manual-request', 'manualRequest')->name('manual-request');
            Route::get('history', 'history')->name('history');
            Route::post('request-action', 'requestAction')->name('request-action');
        });
        Route::resource('method', DepositMethodController::class)->except('show');
    });

    // ======================== 🏦 Withdraw Management   ===============================
    Route::prefix('withdraw')->as('withdraw.')->middleware('admin.feature:withdraw_money')->group(function () {
        Route::controller(WithdrawController::class)->group(function () {
            Route::get('manual-request', 'manualRequest')->name('manual-request');
            Route::get('history', 'history')->name('history');
            Route::post('request-action', 'requestAction')->name('request-action');
        });
        Route::resource('method', WithdrawMethodController::class)->except('show');
        Route::controller(WithdrawScheduleController::class)->group(function () {
            Route::get('schedule', 'index')->name('schedule');
            Route::post('schedule-update', 'update')->name('schedule.update');
        });
    });

    // ======================== 🌍 International Remittance ===============================
    Route::prefix('remittance')->as('remittance.')->middleware('admin.feature:remittance')->group(function () {
        Route::resource('corridors', RemittanceCorridorController::class)
            ->except('show')
            ->parameters(['corridors' => 'corridor']);

        Route::prefix('transfers')->as('transfers.')->controller(RemittanceTransferController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('export', 'export')->name('export');
            Route::get('{transfer}', 'show')->name('show');
            Route::post('{transfer}/approve', 'approve')->name('approve');
            Route::post('{transfer}/fail', 'fail')->name('fail');
            Route::post('{transfer}/refund', 'refund')->name('refund');
        });
    });

    // ======================= 🔁 P2P Trading (Admin) =======================
    Route::prefix('p2p')->as('p2p.')->middleware('admin.feature:p2p_marketplace')->group(function () {
        Route::get('/', [P2PAdminController::class, 'index'])->name('index');

        Route::prefix('settings')->as('settings.')->controller(P2PSettingController::class)->group(function () {
            Route::get('/', 'edit')->name('edit');
            Route::put('/', 'update')->name('update');
        });

        // Per-asset fee overrides, managed from the settings screen above.
        Route::prefix('asset-fees')->as('asset-fees.')->controller(P2PAssetFeeController::class)->group(function () {
            Route::post('/', 'store')->name('store');
            Route::get('{asset_fee}', 'show')->name('show');
            Route::put('{asset_fee}', 'update')->name('update');
            Route::delete('{asset_fee}', 'destroy')->name('destroy');
        });

        Route::resource('methods', P2PPaymentMethodController::class)->only(['index', 'show', 'store', 'update', 'destroy']);

        // Advertisers
        Route::prefix('advertisers')->as('advertisers.')->controller(P2PAdvertiserController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('{user}', 'show')->name('show');
            Route::post('{user}/suspend', 'suspend')->name('suspend');
            Route::post('{user}/reactivate', 'reactivate')->name('reactivate');
        });

        // Ownership proofs on customers' payment accounts
        Route::prefix('payment-accounts')->as('payment-accounts.')->controller(P2PPaymentAccountReviewController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('{paymentAccount}/files/{field}', 'proofFile')->name('file');
            Route::post('{paymentAccount}/verify', 'verify')->name('verify');
            Route::post('{paymentAccount}/reject', 'reject')->name('reject');
        });

        // Disputes
        Route::prefix('disputes')->as('disputes.')->controller(P2PDisputeController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('history', 'history')->name('history');
            Route::get('{dispute}', 'show')->name('show');
            Route::get('{dispute}/messages/{message}/attachment', 'attachment')->name('attachment');
            Route::post('{dispute}/resolve-release', 'resolveRelease')->name('resolve-release');
            Route::post('{dispute}/resolve-refund', 'resolveRefund')->name('resolve-refund');
        });

        Route::post('promotion-packages/position-update', [P2PPromotionPackageController::class, 'positionUpdate'])
            ->name('promotion-packages.position-update');

        Route::resource('promotion-packages', P2PPromotionPackageController::class)
            ->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);

        Route::controller(P2POfferPromotionController::class)->group(function () {
            Route::get('promotions', 'index')->name('promotions.index');
            Route::get('promotion-purchases', 'redirectLegacyPurchases')->name('promotion-purchases.index');
        });
    });

    // ======================= 🛒 Marketplace (Admin) =======================
    Route::prefix('marketplace')->as('marketplace.')->middleware('admin.feature:vendor_marketplace')->group(function () {
        Route::prefix('settings')->as('settings.')->controller(MarketplaceSettingController::class)->group(function () {
            Route::get('/', 'edit')->name('edit');
            Route::put('/', 'update')->name('update');
            Route::get('commission-account', [MarketplaceCommissionAccountController::class, 'show'])->name('commission-account.show');
            Route::get('commission-account/export', [MarketplaceCommissionAccountController::class, 'export'])->name('commission-account.export');
            Route::post('commission-account/provision', 'provisionCommissionAccount')->name('commission-account.provision');
        });

        Route::prefix('vendors')->as('vendors.')->controller(MarketplaceVendorController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('{vendor}/approve', 'approve')->name('approve');
            Route::post('{vendor}/suspend', 'suspend')->name('suspend');
        });

        Route::prefix('orders')->as('orders.')->controller(MarketplaceOrderController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('{order}/refund', 'refund')->name('refund');
        });
    });

    // ======================== 🏆 Referral Management   ===============================
    Route::prefix('referral')->as('referral.')->group(function () {
        Route::get('index', [ReferralController::class, 'index'])->name('index');
        Route::post('store', [ReferralController::class, 'store'])->name('store');
        Route::get('edit/{id}', [ReferralController::class, 'edit'])->name('edit');
        Route::post('update/{id}', [ReferralController::class, 'update'])->name('update');
        Route::get('status-update/{type}/{status}', [ReferralController::class, 'statusUpdate'])->name('status-update');
        Route::delete('delete/{id}', [ReferralController::class, 'destroy'])->name('delete');
        Route::get('card-content', [ReferralController::class, 'cardContent'])->name('card.content');
        Route::post('content-update', [ReferralController::class, 'contentUpdate'])->name('content.update');
    });

    // ======================== User Ranking Management   ===============================
    Route::resource('ranking', UserRankController::class)
        ->except(['create', 'show', 'destroy'])
        ->middleware('admin.feature:user_ranks');

    // ======================== 🔄 Transaction Management  ===============================
    Route::get('transaction', [TransactionController::class, 'index'])->name('transaction');
    Route::get('transaction/export', [TransactionController::class, 'export'])->name('transaction.export');

    /*
     * `{trx_id}` is declared last on purpose. It would otherwise swallow
     * `transaction/export`, whose literal segment is a perfectly good
     * transaction id as far as the router is concerned, and the CSV download
     * would start answering with a 404 detail page instead.
     */
    Route::get('transaction/{trx_id}', [TransactionController::class, 'show'])->name('transaction.show');
    Route::get('transaction/{trx_id}/receipt', [TransactionController::class, 'receipt'])->name('transaction.receipt');
    Route::post('transaction/{trx_id}/action', [TransactionController::class, 'action'])->name('transaction.action');
    Route::post('transaction/{trx_id}/refund', [TransactionController::class, 'refund'])->name('transaction.refund');

    // ======================== ⚙️ Site Management  ==============================
    Route::prefix('settings')->as('settings.')->group(function () {
        Route::post('site/storage-link', [SettingController::class, 'linkStorage'])->name('site.storage-link');
        Route::resource('site', SettingController::class)->only(['index', 'update']);
        Route::post('plugin/{plugin}/test', [PluginController::class, 'test'])->name('plugin.test');
        /* The Integration Center's card switch. Declared above the
           {plugin_type} catch-all below, which would otherwise match it. */
        Route::patch('plugin/{plugin}/status', [PluginController::class, 'toggleStatus'])->name('plugin.toggle');
        Route::resource('plugin', PluginController::class)->only(['index', 'edit', 'update']);
        Route::get('{plugin_type}', [PluginController::class, 'pluginType'])->name('plugin_type');
    });

    // ======================== 🎫 Support Ticket  ==============================
    Route::prefix('support-ticket')->as('support-ticket.')->controller(TicketController::class)->group(function () {
        Route::resource('category', SupportCategoryController::class)->except(['show', 'create']);
        Route::get('pending', 'pendingTicket')->name('new');
        Route::get('inprogress', 'inprogress')->name('inprogress');
        Route::get('close', 'closeTicket')->name('close');
        Route::get('history', 'history')->name('history');
        Route::get('show/{ticket}', 'ticketShow')->name('show');
        Route::post('reply/{ticket}', 'ticketReplyStore')->name('reply');
        Route::put('status-update/{ticket_id}', 'statusUpdate')->name('status-update');
    });

    //  ️️======================== 🔔 Notification Management  ==============================
    Route::prefix('notifications')->name('notifications.')->group(function () {

        // 🔹 Notification management routes (prefix: notification)
        Route::controller(NotificationController::class)->group(function () {
            // 🔹 Admin-triggered user notification
            Route::get('to-users', 'notifyUsers')->name('notifyToUser');
            Route::post('to-users/send', 'sendNotification')->name('notifyToUser.send');

            // 🔹 Display notifications
            Route::get('/', 'index')->name('index');
            Route::get('/recent', 'recent')->name('recent');
            Route::get('/signature', 'signature')->name('signature');

            // 🔹 State-changing actions (use PATCH)
            Route::get('/{notification}/read', 'markAsRead')->name('markAsRead');
            Route::get('/read-all', 'markAllAsRead')->name('markAllAsRead');
        });

        // 🔹 Template management routes (prefix: template)
        Route::prefix('template')->name('template.')->controller(NotificationTemplateController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('{template}/edit', 'edit')->name('edit');
            Route::put('{template}/channel/{channel}', 'updateChannel')->name('update');
        });

    });

    // ======================= 🌎 Language Management =======================
    Route::prefix('language')->name('language.')->controller(LanguageController::class)->group(function () {
        Route::get('translate/{code}', 'translate')->name('translate');
        Route::post('translate-update', 'translatedUpdate')->name('translate-update');
        Route::get('sync-missing-keys', 'syncMissingKeys')->name('sync-missing-keys');
        Route::post('position-update', 'positionUpdate')->name('position-update');
    });
    // 🔹 Resource Language CRUD
    Route::resource('language', LanguageController::class);

    // ======================= 🎉 Custom Landing Page =======================
    Route::prefix('custom-landing')->name('custom-landing.')->controller(CustomLandingController::class)->group(function () {
        Route::get('guide', 'guide')->name('guide');
        Route::post('{custom_landing}/activate', 'activate')->name('activate');
        Route::get('{custom_landing}/manage-html', 'manageHtml')->name('manage-html');
        Route::post('{custom_landing}/manage-html-update', 'manageHtmlUpdate')->name('manage-html-update')->withoutMiddleware('XSS');
    });
    Route::resource('custom-landing', CustomLandingController::class);

    // ======================= 🏷️ Navigation Management =======================
    Route::prefix('navigation')->as('navigation.')->controller(NavigationController::class)->group(function () {
        Route::resource('site', NavigationController::class)->except(['create', 'show']);
        Route::post('position-update', 'positionUpdate')->name('position-update');
    });

    // ======================= 🎨 Theme Manager =======================
    Route::prefix('theme-manager')->as('theme-manager.')->controller(ThemeManagerController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('activate/{theme}', 'activate')->name('activate');
        Route::post('colors/{theme}', 'updateColors')->name('colors.update');
        Route::post('colors/{theme}/reset', 'resetColors')->name('colors.reset');
    });

    Route::prefix('media')->as('media.')->group(function () {
        Route::controller(MediaStorageProviderController::class)
            ->prefix('storage-providers')
            ->as('storage-providers.')
            ->group(function () {
                Route::post('/', 'store')->name('store');
                Route::put('{provider}', 'update')->name('update');
                Route::post('{provider}/activate', 'activate')->name('activate');
                Route::post('{provider}/test', 'test')->name('test');
                Route::delete('{provider}', 'destroy')->name('destroy');
            });

        Route::controller(MediaAssetController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::put('{mediaAsset}', 'update')->name('update');
            Route::delete('{mediaAsset}', 'destroy')->name('destroy');
        });
    });

    // ======================= 📄 Page Management =======================
    Route::prefix('page')->as('page.')->group(function () {
        Route::resource('site', PageController::class)->except('show');
        Route::resource('component', PageComponentController::class)->except('show')->withoutMiddleware('XSS');
        Route::resource('component-repeated-content', PageComponentRepeatedContentController::class)->only(['edit', 'store', 'update', 'destroy']);

        // 🔹 Page Footer
        Route::prefix('footer')->as('footer.')->group(function () {

            // Footer Section Routes
            Route::resource('section', FooterSectionController::class)->except(['show', 'create']);
            Route::post('section/position-update', [FooterSectionController::class, 'positionUpdate'])->name('section.position-update');

            // Footer Item Routes
            Route::resource('item', FooterItemController::class)->except(['show', 'create']);
            Route::post('item/position-update', [FooterItemController::class, 'positionUpdate'])->name('item.position-update');

        });
    });

    // ======================= 📰 Blog Management =======================
    Route::prefix('blog')->as('blog.')->group(function () {
        Route::resource('post', BlogController::class)->withoutMiddleware('XSS');
        Route::resource('category', BlogCategoryController::class);
    });

    // ======================== 📱 Social Management ========================
    Route::resource('social', SocialController::class)->except(['create', 'show']);

    // ======================= 🔍 Site SEO Management =======================
    Route::resource('site-seo', SiteSeoController::class)->except(['show']);

    // ======================= 📧 Subscriber Management =======================
    Route::prefix('subscriber')->as('subscriber.')->controller(SubscriberController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('send-mail', 'sendMail')->name('send-mail');
        Route::delete('delete/{id}', 'deleteSubscriber')->name('delete');
    });

    // 🔻 Dismissible dashboard-notice endpoint. Whitelisted in the
    //    controller — only known notice keys are accepted.
    Route::post('notices/{key}/dismiss', [AdminNoticeController::class, 'dismiss'])
        ->where('key', '[a-z0-9\-]+')
        ->name('notice.dismiss');

    // ======================= ⚙️ Background Task Management =======================
    Route::prefix('background-tasks')->as('background-tasks.')->group(function () {
        Route::controller(BackgroundTaskController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('run', 'run')->name('run');
            Route::get('logs', 'logs')->name('logs');
            Route::get('scheduler', 'scheduler')->name('scheduler');
        });
    });

    Route::prefix('queue')->as('queue.')->controller(QueueManagementController::class)->group(function () {
        Route::get('failed', 'failed')->name('failed');
        Route::post('retry/{id}', 'retry')->name('retry');
        Route::post('retry-all', 'retryAll')->name('retry-all');
        Route::delete('forget/{id}', 'forget')->name('forget');
        // Deletes only the jobs whose payload can no longer be restored,
        // so a table of dead notifications does not have to take the
        // retryable jobs with it.
        Route::post('flush-unrestorable', 'flushUnrestorable')->name('flush-unrestorable');
        Route::post('flush', 'flush')->name('flush');
    });

    // ======================= 🔗 Payment Link Management =======================
    Route::prefix('payment-links')->as('payment-links.')->controller(PaymentLinkController::class)
        ->middleware('admin.feature:payment_link')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('{paymentLink}', 'show')->name('show');
            Route::post('{paymentLink}/toggle-status', 'toggleStatus')->name('toggle-status');
            Route::delete('{paymentLink}', 'destroy')->name('destroy');
        });

    // ======================= Mobile Recharge Management =======================
    Route::prefix('mobile-recharge')->as('mobile-recharge.')
        ->middleware('admin.feature:mobile_recharge')
        ->group(function () {
            Route::prefix('providers')->as('providers.')->controller(MobileRechargeProviderController::class)->group(function () {
                Route::get('create', 'create')->name('create');
                Route::post('/', 'store')->name('store');
                Route::get('{provider}/edit', 'edit')->name('edit');
                Route::put('{provider}', 'update')->name('update');
                Route::delete('{provider}', 'destroy')->name('destroy');
                // Probes the provider's live credentials; throttled because
                // each call reaches the upstream API.
                Route::post('{provider}/test-connection', 'providerTestConnection')
                    ->middleware('throttle:10,1')
                    ->name('test-connection');
            });

            Route::controller(MobileRechargeController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('{mobileRecharge}', 'show')->name('show');
            });
        });

    // ========================= Bill Payment Management =========================
    /*
     * Deliberately not behind admin.feature: the module ships off and billers
     * are configured first, and switching it off must never hide pending
     * customer money from the only screen that can refund it.
     *
     * The static segments (billers, categories, providers, queue) are
     * declared ahead of the {billPayment} routes, which also only match a
     * uuid. The provider parameter is {billPaymentProvider} so it is never
     * read as the recharge providers' {provider} routes above.
     */
    Route::prefix('bill-payments')->as('bill-payment.')->group(function () {
        // BP5: billers imported from a CSV, under a provider that pays by hand.
        Route::prefix('billers/import')->as('billers.import')->controller(BillerImportController::class)->group(function () {
            Route::get('/', 'create');
            Route::get('template', 'template')->name('.template');
            Route::post('preview', 'preview')->name('.preview');
            Route::get('{token}', 'show')->where('token', '[a-z0-9]{32}')->name('.show');
            Route::post('{token}', 'store')->where('token', '[a-z0-9]{32}')->name('.store');
        });

        Route::prefix('billers')->as('billers.')->controller(BillerController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('create', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('{biller}/edit', 'edit')->whereNumber('biller')->name('edit');
            Route::put('{biller}', 'update')->whereNumber('biller')->name('update');
            Route::delete('{biller}', 'destroy')->whereNumber('biller')->name('destroy');
            Route::post('{biller}/toggle-status', 'toggleStatus')->whereNumber('biller')->name('toggle-status');
        });

        Route::prefix('categories')->as('categories.')->controller(BillCategoryController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::put('{category}', 'update')->whereNumber('category')->name('update');
            Route::delete('{category}', 'destroy')->whereNumber('category')->name('destroy');
            Route::post('{category}/toggle-status', 'toggleStatus')->whereNumber('category')->name('toggle-status');
        });

        Route::prefix('providers')->as('providers.')->controller(BillPaymentProviderController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('create', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('{billPaymentProvider}/edit', 'edit')->whereNumber('billPaymentProvider')->name('edit');
            Route::put('{billPaymentProvider}', 'update')->whereNumber('billPaymentProvider')->name('update');
            Route::delete('{billPaymentProvider}', 'destroy')->whereNumber('billPaymentProvider')->name('destroy');
            Route::post('{billPaymentProvider}/toggle-status', 'toggleStatus')->whereNumber('billPaymentProvider')->name('toggle-status');
            // Probes the saved credentials; throttled because each call reaches the provider's API.
            Route::post('{billPaymentProvider}/test-connection', 'testConnection')
                ->whereNumber('billPaymentProvider')
                ->middleware('throttle:10,1')
                ->name('test-connection');
            // Queues a catalogue sync; its own limiter so it never shares the
            // per-user counter plain numeric throttles fall back to.
            Route::post('{billPaymentProvider}/sync', 'sync')
                ->whereNumber('billPaymentProvider')
                ->middleware('throttle:5,1,bill-payment-sync')
                ->name('sync');
        });

        Route::controller(BillPaymentController::class)->group(function () {
            // 'showQueue', not 'queue': inside a controller group the router
            // would take 'queue' for the Queue facade class.
            Route::get('queue', 'showQueue')->name('queue');
            Route::get('/', 'index')->name('index');
            Route::get('{billPayment}', 'show')->whereUuid('billPayment')->name('show');
            Route::post('{billPayment}/claim', 'claim')->whereUuid('billPayment')->name('claim');
            Route::post('{billPayment}/mark-paid', 'markPaid')->whereUuid('billPayment')->name('mark-paid');
            Route::post('{billPayment}/mark-failed', 'markFailed')->whereUuid('billPayment')->name('mark-failed');
            // A check reaches the provider's API and a confirmation refunds; both are throttled.
            Route::post('{billPayment}/check-provider', 'checkWithProvider')
                ->whereUuid('billPayment')
                ->middleware('throttle:10,1')
                ->name('check-provider');
            Route::post('{billPayment}/confirm-not-paid', 'confirmNotPaid')
                ->whereUuid('billPayment')
                ->middleware('throttle:10,1')
                ->name('confirm-not-paid');
        });
    });

    // ========================= Mass Payment Management =========================
    /*
     * Deliberately not behind admin.feature: switching the module off must
     * never hide money reserved for a confirmed batch from the only screens
     * that can release it. {batch} only matches a uuid; 'downloadReport', not
     * 'report', so the action is never read as a facade.
     */
    Route::prefix('mass-payments')->as('mass-payment.')->controller(MassPaymentController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('{batch}', 'show')->whereUuid('batch')->name('show');
        Route::get('{batch}/report.csv', 'downloadReport')->whereUuid('batch')->name('report');
        Route::post('{batch}/resume', 'resume')->whereUuid('batch')->middleware('throttle:10,1,mass-payment-admin')->name('resume');
        Route::post('{batch}/cancel-remaining', 'cancelRemaining')->whereUuid('batch')->middleware('throttle:10,1,mass-payment-admin')->name('cancel-remaining');
        Route::post('{batch}/close-after-review', 'closeAfterReview')->whereUuid('batch')->middleware('throttle:10,1,mass-payment-admin')->name('close-after-review');
    });

    /*
     * The actions only a payout batch has. Same prefix and the same reason
     * for staying outside admin.feature; {row} is a line id, looked up only
     * inside {batch}. Reveal answers JSON and is throttled harder, since each
     * call decrypts an account.
     */
    Route::prefix('mass-payments')->as('mass-payout.')->controller(MassPayoutController::class)->group(function () {
        Route::post('{batch}/approve', 'approve')->whereUuid('batch')->middleware('throttle:10,1,mass-payment-admin')->name('approve');
        Route::post('{batch}/reject', 'reject')->whereUuid('batch')->middleware('throttle:10,1,mass-payment-admin')->name('reject');
        Route::post('{batch}/rows/{row}/mark-paid', 'markRowPaid')->whereUuid('batch')->whereNumber('row')->middleware('throttle:30,1,mass-payment-admin')->name('row.mark-paid');
        Route::post('{batch}/rows/{row}/mark-not-paid', 'markRowNotPaid')->whereUuid('batch')->whereNumber('row')->middleware('throttle:30,1,mass-payment-admin')->name('row.mark-not-paid');
        Route::post('{batch}/rows/{row}/check', 'checkRow')->whereUuid('batch')->whereNumber('row')->middleware('throttle:30,1,mass-payment-admin')->name('row.check');
        Route::post('{batch}/rows/{row}/reveal', 'revealAccount')->whereUuid('batch')->whereNumber('row')->middleware('throttle:20,1,mass-payout-reveal')->name('row.reveal');
    });

    // ========================= Taxes & Levies (GF6) =========================
    Route::prefix('tax-rules')->as('tax-rule.')->controller(TaxRuleController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('{taxRule}/edit', 'edit')->whereNumber('taxRule')->name('edit');
        Route::put('{taxRule}', 'update')->whereNumber('taxRule')->name('update');
        Route::delete('{taxRule}', 'destroy')->whereNumber('taxRule')->name('destroy');
        Route::post('{taxRule}/toggle', 'toggle')->whereNumber('taxRule')->name('toggle');
    });

    // ======================= 💳 Subscription Management =======================
    Route::prefix('subscription')->as('subscription.')->group(function () {
        Route::resource('plans', SubscriptionPlanController::class)->except(['show']);
        Route::post('plans/{plan}/toggle-status', [SubscriptionPlanController::class, 'toggleStatus'])->name('plans.toggle-status');

        Route::prefix('user-subscriptions')->as('user-subscriptions.')->controller(AdminUserSubscriptionController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('{subscription}', 'show')->name('show');
            Route::post('{subscription}/activate', 'activate')->name('activate');
            Route::post('{subscription}/cancel', 'cancel')->name('cancel');
        });

        Route::get('transactions', [AdminUserSubscriptionController::class, 'transactions'])->name('transactions');
    });

    // ======================= 🚀 Application Tools =======================
    Route::prefix('app')->as('app.')->controller(AppController::class)->group(function () {
        Route::get('/', 'appInfo')->name('info');
        Route::get('/report', 'appInfoReport')->name('info.report');

        // Menu search functionality
        Route::get('menu-search', 'getMenusForSearch')->name('menu-search');
        Route::get('/control-panel', 'controlPanel')->name('control-panel');

        Route::post('/smtp-test-email', 'sendSmtpTestEmail')->name('smtp-test-email');

        Route::get('style-manager', 'styleManager')->name('style-manager');
        Route::post('style-manager', 'styleManagerUpdate')->name('style-manager-update');

        Route::get('/optimize', 'optimize')->name('optimize');
        Route::get('/clear-cache', 'clearCache')->name('clear-cache');

    });

    /*
     * The command palette. Open to every signed-in admin — what each scope
     * shows them is decided per scope, from the same permission list the
     * sidebar reads — and throttled because it fires on every keystroke.
     */
    Route::get('search', GlobalSearchController::class)
        ->name('app.global-search')
        ->middleware('throttle:60,1');

    Route::prefix('app/updater')->as('app.updater.')->controller(ProjectUpdaterController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('activate', 'activate')->name('activate');
        Route::post('refresh-license', 'refreshLicense')->name('refresh-license');
        Route::post('check', 'check')->name('check');
        Route::post('backup/download', 'backupDownload')->name('backup.download');
        Route::post('install/{update}', 'install')->name('install');
        Route::post('rollback/{update}', 'rollback')->name('rollback');
    });

});
