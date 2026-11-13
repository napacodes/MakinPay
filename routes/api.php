<?php

use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\StripeController;
use App\Http\Controllers\Api\V1\Agent\AgentController as MobileAgentController;
use App\Http\Controllers\Api\V1\Agent\AgentOperationController as MobileAgentOperationController;
use App\Http\Controllers\Api\V1\Agent\AgentQrController as MobileAgentQrController;
use App\Http\Controllers\Api\V1\Auth\AuthController as MobileAuthController;
use App\Http\Controllers\Api\V1\Auth\DeviceController as MobileDeviceController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController as MobilePasswordResetController;
use App\Http\Controllers\Api\V1\Auth\VerificationController as MobileVerificationController;
use App\Http\Controllers\Api\V1\BeneficiaryController as ApiBeneficiaryController;
use App\Http\Controllers\Api\V1\BillPaymentController as BillPaymentApiController;
use App\Http\Controllers\Api\V1\BroadcastAuthController as MobileBroadcastAuthController;
use App\Http\Controllers\Api\V1\BudgetController as MobileBudgetController;
use App\Http\Controllers\Api\V1\ContactController as MobileContactController;
use App\Http\Controllers\Api\V1\DashboardController as MobileDashboardController;
use App\Http\Controllers\Api\V1\FeatureController as MobileFeatureController;
use App\Http\Controllers\Api\V1\GiftCardController as MobileGiftCardController;
use App\Http\Controllers\Api\V1\InsightsController as MobileInsightsController;
use App\Http\Controllers\Api\V1\KycController as MobileKycController;
use App\Http\Controllers\Api\V1\MarketplaceController as MobileMarketplaceController;
use App\Http\Controllers\Api\V1\MassPaymentController as MobileMassPaymentController;
use App\Http\Controllers\Api\V1\MassPayoutController as MobileMassPayoutController;
use App\Http\Controllers\Api\V1\Merchant\MerchantController as MobileMerchantController;
use App\Http\Controllers\Api\V1\Merchant\PaymentLinkController as MobilePaymentLinkController;
use App\Http\Controllers\Api\V1\MerchantSubscriptionController as ApiMerchantSubscriptionController;
use App\Http\Controllers\Api\V1\MobileRechargeController as MobileRechargeApiController;
use App\Http\Controllers\Api\V1\Money\DepositController as MobileDepositController;
use App\Http\Controllers\Api\V1\Money\ExchangeController as MobileExchangeController;
use App\Http\Controllers\Api\V1\Money\MerchantPaymentController as MobileMerchantPaymentController;
use App\Http\Controllers\Api\V1\Money\RemittanceController as MobileRemittanceController;
use App\Http\Controllers\Api\V1\Money\RemittanceRecipientController as MobileRemittanceRecipientController;
use App\Http\Controllers\Api\V1\Money\RequestMoneyController as MobileRequestMoneyController;
use App\Http\Controllers\Api\V1\Money\SendMoneyController as MobileSendMoneyController;
use App\Http\Controllers\Api\V1\Money\WithdrawController as MobileWithdrawController;
use App\Http\Controllers\Api\V1\NotificationController as MobileNotificationController;
use App\Http\Controllers\Api\V1\NotificationPreferencesController as MobileNotificationPreferencesController;
use App\Http\Controllers\Api\V1\OpenApiController as MobileOpenApiController;
use App\Http\Controllers\Api\V1\P2PMarketController as MobileP2PMarketController;
use App\Http\Controllers\Api\V1\P2POfferController as MobileP2POfferController;
use App\Http\Controllers\Api\V1\P2POfferPromotionController as MobileP2POfferPromotionController;
use App\Http\Controllers\Api\V1\P2POrderController as MobileP2POrderController;
use App\Http\Controllers\Api\V1\P2PPaymentAccountController as MobileP2PPaymentAccountController;
use App\Http\Controllers\Api\V1\PayResolveController as MobilePayResolveController;
use App\Http\Controllers\Api\V1\ProfileController as MobileProfileController;
use App\Http\Controllers\Api\V1\RankController as MobileRankController;
use App\Http\Controllers\Api\V1\RateController as MobileRateController;
use App\Http\Controllers\Api\V1\ReferenceController as MobileReferenceController;
use App\Http\Controllers\Api\V1\ReferralController as MobileReferralController;
use App\Http\Controllers\Api\V1\RemittanceController as ApiRemittanceController;
use App\Http\Controllers\Api\V1\RewardsController as MobileRewardsController;
use App\Http\Controllers\Api\V1\ScheduledPaymentController as MobileScheduledPaymentController;
use App\Http\Controllers\Api\V1\SecurityController as MobileSecurityController;
use App\Http\Controllers\Api\V1\TicketController as MobileTicketController;
use App\Http\Controllers\Api\V1\TransactionController as MobileTransactionController;
use App\Http\Controllers\Api\V1\TwoFactorController as MobileTwoFactorController;
use App\Http\Controllers\Api\V1\VaultController as MobileVaultController;
use App\Http\Controllers\Api\V1\VirtualCardController as MobileVirtualCardController;
use App\Http\Controllers\Api\V1\VoucherController as MobileVoucherController;
use App\Http\Controllers\Api\V1\WalletController as MobileWalletController;
use App\Http\Controllers\Api\V1\WalletEarnController as MobileWalletEarnController;
use App\Http\Controllers\Api\V1\WalletPinController as MobileWalletPinController;
use Illuminate\Support\Facades\Route;

Route::middleware('merchant.auth')->group(function () {
    Route::group(['prefix' => 'v1'], function () {

        // Create a payment (stateless; no DB record is stored)
        Route::post('initiate-payment', [PaymentController::class, 'initiatePayment']);

        // Payment Verification
        Route::get('verify-payment/{trxId}', [PaymentController::class, 'verifyPayment']);

        // Refund a completed payment (full or partial)
        Route::post('refund-payment', [PaymentController::class, 'refundPayment']);

        // site info
        Route::get('site-info', [PaymentController::class, 'siteInfo']);

        Route::prefix('subscriptions')->middleware('cache.headers:private;no_store')->controller(ApiMerchantSubscriptionController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store')->middleware('merchant.idempotency');
            Route::get('{subscription}', 'show');
            Route::post('{subscription}/cancel', 'cancel')->middleware('merchant.idempotency');
            Route::post('{subscription}/renew', 'renew')->middleware('merchant.idempotency');
        });
    });
});

Route::middleware('auth:sanctum')
    ->post('/stripe/issuing/ephemeral-key', [StripeController::class, 'createEphemeralKey'])->name('stripe.issuing.ephemeral-key');

/*
|--------------------------------------------------------------------------
| End-user API (Sanctum personal access tokens)
|--------------------------------------------------------------------------
|
| Mobile app + third-party integrations call these endpoints with a
| personal-access-token issued via the user dashboard.
|
*/
Route::middleware([
    'auth:sanctum',
    'account.status.check',
    // The same admin toggle the web panel honours. The stock `verified`
    // refused unverified API users even on sites where verification is off.
    'verified.when.required',
    'feature.enabled:remittance',
    'throttle:remittance-api',
])->prefix('v1')->group(function () {

    Route::prefix('remittance')->controller(ApiRemittanceController::class)->group(function () {
        Route::get('corridors', 'corridors');
        Route::post('quote', 'quote')->middleware('throttle:remittance-quotes');
        Route::post('send', 'send')->middleware('throttle:remittance-sends');
        Route::get('transfers', 'history');
        Route::get('transfers/{reference}', 'track');
    });

    Route::apiResource('beneficiaries', ApiBeneficiaryController::class);
});

/*
|--------------------------------------------------------------------------
| Mobile app API (Sanctum device tokens)
|--------------------------------------------------------------------------
|
| Everything the Flutter client calls. One surface for all three roles - the
| reply carries `user.role` and the app chooses its shell from that.
|
| `api.envelope` is first in the group so a failure raised by the throttle or
| by `auth:sanctum`, both of which run after it, still answers in the app's
| error shape. It is a marker: the merchant server-to-server endpoints above
| share this prefix and keep their own `{error, message}` body.
|
*/
Route::prefix('v1')
    ->middleware(['api.envelope', 'api.locale', 'api.version'])
    ->name('mobile.')
    ->group(function () {

        Route::prefix('auth')->controller(MobileAuthController::class)->group(function () {
            Route::post('login', 'login')
                ->middleware('throttle:api-auth')
                ->name('login');

            Route::post('register', 'register')
                ->middleware('throttle:api-auth')
                ->name('register');

            // The sign-up form asks as the customer types; on the sign-in
            // bucket because a name check is one guess per request.
            Route::get('username-available', [MobileReferenceController::class, 'usernameAvailable'])
                ->middleware('throttle:api-auth')
                ->name('username-available');

            // Second half of a sign-in that produced a challenge instead of a
            // token. Throttled alongside login, because it is the same attempt.
            Route::post('2fa/verify', 'twoFactorVerify')
                ->middleware('throttle:api-auth')
                ->name('2fa.verify');

            // GF9: sign-in by SMS code, when the admin has switched it on. On
            // the sign-in bucket like a password, with the service's own
            // per-number and per-address limits on top.
            Route::post('otp/request', 'otpRequest')
                ->middleware('throttle:api-auth')
                ->name('otp.request');
            Route::post('otp/verify', 'otpVerify')
                ->middleware('throttle:api-auth')
                ->name('otp.verify');

            /*
             * Throttled like any other write. Signing out needs a valid
             * token and destroys it, so there is little to gain by
             * hammering it - but "every route is rate limited" is a property
             * worth being able to assert about the whole surface rather than
             * a judgement to re-make per route, and these two were the only
             * pair without one.
             */
            Route::middleware(['auth:sanctum', 'throttle:api-write'])->group(function () {
                Route::post('logout', 'logout')->name('logout');
                Route::post('logout-all', 'logoutAll')->name('logout-all');
            });
        });

        /*
         * The API's own description. Outside every gate that could stop an
         * integrator reading it - including the version gate, since the
         * document is how a client learns what the current API even is.
         */
        Route::get('openapi.json', MobileOpenApiController::class)
            ->middleware('throttle:api-read')
            ->name('openapi');

        // Reference data the sign-in screen needs before anyone has a token.
        // Nothing here depends on who is asking, so the limit is by address.
        Route::middleware('throttle:api-read')
            ->controller(MobileReferenceController::class)
            ->group(function () {
                // Not 'config': a group controller action is first tried as a class
                // name, and class names ignore case - the Config facade alias would win.
                Route::get('config', 'showConfig')->name('config');
                Route::get('currencies', 'currencies')->name('currencies');
                Route::get('countries', 'countries')->name('countries');
            });

        // Reachable without a token, and rate limited by address: a customer
        // who cannot sign in is exactly who needs these.
        Route::prefix('auth')
            ->middleware('throttle:api-auth')
            ->controller(MobilePasswordResetController::class)
            ->group(function () {
                Route::post('forgot-password', 'forgot')->name('forgot-password');
                Route::post('reset-password', 'reset')->name('reset-password');
            });

        /*
         * Finishing sign-up by code, before there is a token: a site that
         * requires verification hands none out until the address is proved.
         * On the sign-in bucket, since a code is a guess.
         */
        Route::prefix('auth/email')
            ->middleware('throttle:api-auth')
            ->controller(MobileVerificationController::class)
            ->group(function () {
                Route::post('verify-code', 'verifyEmailCode')->name('email.verify-code');
                Route::post('resend-code', 'resendEmailCode')->name('email.resend-code');
            });

        Route::prefix('auth')
            ->middleware(['auth:sanctum', 'account.status.check', 'throttle:api-write'])
            ->controller(MobileVerificationController::class)
            ->group(function () {
                Route::post('email/resend', 'resendEmail')->name('email.resend');
                Route::post('phone/send-otp', 'sendPhoneCode')->name('phone.send-otp');
                Route::post('phone/verify', 'verifyPhone')->name('phone.verify');
            });

        Route::middleware(['auth:sanctum', 'account.status.check', 'throttle:api-read'])->group(function () {
            Route::get('me', [MobileProfileController::class, 'show'])->name('me');
            Route::get('me/wallet-pin', [MobileWalletPinController::class, 'show'])->name('me.wallet-pin');
            Route::get('me/2fa', [MobileTwoFactorController::class, 'show'])->name('me.2fa');
            Route::get('me/security', [MobileSecurityController::class, 'show'])->name('me.security');
            Route::get('me/sessions', [MobileSecurityController::class, 'sessions'])->name('me.sessions');
            Route::get('rank', [MobileRankController::class, 'show'])
                ->middleware('feature.enabled:user_ranks')
                ->name('rank');
            Route::middleware('feature.enabled:referral_program')->group(function () {
                Route::get('referral', [MobileReferralController::class, 'show'])->name('referral');
                Route::get('referral/referred', [MobileReferralController::class, 'referred'])->name('referral.referred');
                Route::post('referral/nudge', [MobileReferralController::class, 'nudge'])->middleware('throttle:api-write')->name('referral.nudge');
            });
            /*
             * The rewards hub reads across three programmes; each tile is
             * hidden by its own feature switch inside, so the hub itself is
             * always there.
             */
            Route::get('rewards', [MobileRewardsController::class, 'show'])->name('rewards');
            Route::get('rewards/history', [MobileRewardsController::class, 'history'])->name('rewards.history');
            Route::middleware('feature.enabled:wallet_earn')->prefix('earn')->as('earn.')->controller(MobileWalletEarnController::class)->group(function () {
                Route::get('plans', 'plans')->name('plans');
                Route::get('stakes', 'stakes')->name('stakes');
                Route::get('stakes/{stake}', 'show')->name('stakes.show');
            });
            Route::get('vouchers', [MobileVoucherController::class, 'index'])
                ->middleware('feature.enabled:vouchers')
                ->name('vouchers.index');
            Route::get('gift-cards/templates', [MobileGiftCardController::class, 'templates'])
                ->middleware('feature.enabled:gift_cards')
                ->name('gift-cards.templates');
            // What a code is worth before it is redeemed: a read, with the
            // secret in the query and no money moving.
            Route::get('gift-cards/lookup', [MobileGiftCardController::class, 'lookup'])
                ->middleware(['feature.enabled:gift_cards', 'throttle:api-read'])
                ->name('gift-cards.lookup');
            Route::get('gift-cards', [MobileGiftCardController::class, 'index'])
                ->middleware('feature.enabled:gift_cards')
                ->name('gift-cards.index');
            Route::middleware('feature.enabled:vendor_marketplace')->prefix('marketplace')->as('marketplace.')->controller(MobileMarketplaceController::class)->group(function () {
                Route::get('vendors', 'vendors')->name('vendors');
                Route::get('vendors/{vendor}', 'showVendor')->name('vendors.show');
                Route::get('orders', 'orders')->name('orders');
            });
            Route::middleware('feature.enabled:mobile_recharge')->prefix('mobile-recharge')->as('mobile-recharge.')->controller(MobileRechargeApiController::class)->group(function () {
                Route::get('/', 'show')->name('show');
                Route::get('quote', 'quote')->name('quote');
                Route::get('operator', 'operator')->name('operator');
                Route::get('plans', 'plans')->name('plans');
            });
            // Literal paths before the uuid parameter, or they would be read
            // as one. The single payment's action is showPayment, not
            // 'payment': inside a controller group a string action named
            // after a global class (Payment) is taken for that class.
            Route::middleware('feature.enabled:bill_payment')->prefix('bill-payments')->as('bill-payments.')->controller(BillPaymentApiController::class)->group(function () {
                Route::get('/', 'show')->name('show');
                Route::get('billers', 'billers')->name('billers');
                Route::get('billers/{biller}', 'biller')->whereNumber('biller')->name('biller');
                Route::get('quote', 'quote')->name('quote');
                Route::get('history', 'history')->name('history');
                Route::get('{billPayment}', 'showPayment')->whereUuid('billPayment')->name('payment');
            });
            // The website's own P2P gates: the admin's P2P switch, the catalog switch, the country rules.
            Route::middleware(['p2p.enabled', 'feature.enabled:p2p_marketplace', 'p2p.country'])->prefix('p2p')->as('p2p.')->group(function () {
                // my before {offer}, or "my" is read as an offer id.
                Route::get('offers', [MobileP2POfferController::class, 'index'])->name('offers.index');
                Route::get('market-price', [MobileP2PMarketController::class, 'price'])->name('market-price');
                Route::get('market-alerts', [MobileP2PMarketController::class, 'alerts'])->name('market-alerts.index');
                Route::get('offers/my', [MobileP2POfferController::class, 'my'])->name('offers.my');
                Route::get('offers/my/stats', [MobileP2POfferController::class, 'stats'])->name('offers.stats');
                Route::get('offers/{offer}', [MobileP2POfferController::class, 'show'])->name('offers.show');
                Route::get('advertisers/{user}', [MobileP2POfferController::class, 'profile'])->name('advertisers.show');
                Route::get('traders/{user}', [MobileP2POfferController::class, 'profile'])->name('traders.show');
                Route::get('payment-accounts', [MobileP2PPaymentAccountController::class, 'index'])->name('payment-accounts.index');
                Route::get('payment-accounts/{account}/files/{field}', [MobileP2PPaymentAccountController::class, 'file'])->name('payment-accounts.file');
                Route::get('orders', [MobileP2POrderController::class, 'index'])->name('orders.index');
                Route::get('orders/{order}', [MobileP2POrderController::class, 'show'])->name('orders.show');
                Route::get('orders/{order}/status', [MobileP2POrderController::class, 'status'])->name('orders.status');
                Route::get('orders/{order}/messages', [MobileP2POrderController::class, 'messages'])->name('orders.messages.index');
                Route::get('orders/{order}/messages/{message}/attachment', [MobileP2POrderController::class, 'attachment'])->name('orders.messages.attachment');
                Route::get('orders/{order}/payment-files/{side}/{field}', [MobileP2POrderController::class, 'paymentFile'])->whereIn('side', ['payer', 'receiver'])->name('orders.payment-file');
            });
            Route::middleware('feature.enabled:virtual_card')->prefix('virtual-cards')->as('virtual-cards.')->controller(MobileVirtualCardController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('requests', 'requests')->name('requests');
                Route::get('eligible-wallets', 'eligibleWallets')->name('eligible-wallets');
                Route::get('{card}/activity', 'activity')->name('activity');
                Route::get('{card}/quote', 'quote')->name('quote');
            });
            Route::get('kyc', [MobileKycController::class, 'show'])->name('kyc');
            Route::get('kyc/templates', [MobileKycController::class, 'templates'])->name('kyc.templates');

            // options before {ticket}, or "options" is read as a ticket id.
            Route::get('tickets', [MobileTicketController::class, 'index'])->name('tickets.index');
            Route::get('tickets/options', [MobileTicketController::class, 'options'])->name('tickets.options');
            Route::get('tickets/{ticket}', [MobileTicketController::class, 'show'])->name('tickets.show');

            Route::get('features', [MobileFeatureController::class, 'index'])->name('features');

            Route::get('dashboard', [MobileDashboardController::class, 'index'])->name('dashboard');

            Route::get('notifications', [MobileNotificationController::class, 'index'])->name('notifications.index');
            Route::get('notifications/unread-count', [MobileNotificationController::class, 'unreadCount'])->name('notifications.unread');
            Route::get('notification-preferences', [MobileNotificationPreferencesController::class, 'show'])->name('notification-preferences.show');
            Route::get('insights', [MobileInsightsController::class, 'overview'])->name('insights.overview');
            Route::get('insights/categories/{category}', [MobileInsightsController::class, 'category'])->name('insights.category');
            Route::get('budgets', [MobileBudgetController::class, 'index'])->middleware('feature.enabled:budgets')->name('budgets.index');
            Route::middleware('feature.enabled:vaults')->prefix('vaults')->as('vaults.')->controller(MobileVaultController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('{vault}', 'show')->name('show');
            });
            Route::middleware('feature.enabled:scheduled_payments')->prefix('scheduled')->as('scheduled.')->controller(MobileScheduledPaymentController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('{schedule}', 'show')->name('show');
            });

            Route::get('transactions/summary', [MobileTransactionController::class, 'summary'])->name('transactions.summary');
            Route::get('transactions/export.{format}', [MobileTransactionController::class, 'export'])->name('transactions.export');
            Route::get('transactions', [MobileTransactionController::class, 'index'])->name('transactions.index');
            Route::get('transactions/{transaction}', [MobileTransactionController::class, 'show'])->name('transactions.show');

            Route::get('wallets', [MobileWalletController::class, 'index'])->name('wallets.index');
            // Literal paths before the uuid parameter, or they would be read as one.
            Route::get('wallets/available-currencies', [MobileWalletController::class, 'availableCurrencies'])->name('wallets.available-currencies');
            Route::get('wallets/balance-history', [MobileWalletController::class, 'history'])->name('wallets.balance-history');
            Route::get('wallets/{wallet}', [MobileWalletController::class, 'show'])->name('wallets.show');
            Route::get('wallets/{wallet}/qr', [MobileWalletController::class, 'qr'])->name('wallets.qr');
            Route::get('wallets/{wallet}/balance-history', [MobileWalletController::class, 'walletHistory'])->name('wallets.history');
            Route::get('wallets/{wallet}/statements', [MobileWalletController::class, 'statements'])->name('wallets.statements');
            Route::get('wallets/{wallet}/statements/{period}.{format}', [MobileWalletController::class, 'statement'])->name('wallets.statement');

            Route::get('auth/devices', [MobileDeviceController::class, 'index'])->name('devices.index');
            Route::delete('auth/devices/{device}', [MobileDeviceController::class, 'destroy'])->name('devices.destroy');

            /*
             * Push registration. On the READ limit rather than the write one
             * because an app re-registers on every cold start - that is how
             * a rotated token is noticed - and a customer opening the app
             * repeatedly is not doing anything unusual.
             */
            /*
             * Subscribing to a private channel. On the read limit because
             * Echo re-authorises on every reconnect, and a phone changing
             * cells reconnects more than it does anything else.
             */
            Route::post('broadcasting/auth', MobileBroadcastAuthController::class)->name('broadcasting.auth');

            Route::post('devices/push-token', [MobileDeviceController::class, 'storePushToken'])->name('devices.push-token.store');
            Route::delete('devices/push-token', [MobileDeviceController::class, 'destroyPushToken'])->name('devices.push-token.destroy');
        });

        /*
         * Agent surface. Three gates, because they answer three questions:
         * the module has to be switched on for this installation, the account
         * has to still be an agent, and the token has to have been issued as
         * one.
         */
        Route::prefix('agent')
            ->name('agent.')
            ->middleware([
                'auth:sanctum',
                'account.status.check',
                'feature.enabled:agent_program',
                'api.role:agent',
                'throttle:api-read',
            ])
            ->controller(MobileAgentController::class)
            ->group(function () {
                Route::get('dashboard', 'dashboard')->name('dashboard');
                Route::get('profile', 'profile')->name('profile');
                Route::get('operations', 'operations')->name('operations.index');
                Route::get('operations/{operation}', 'show')->name('operations.show');
                Route::get('customer', 'customer')->name('customer');
                Route::get('commission', 'commission')->name('commission');
                Route::get('qr-code', 'qrCode')->name('qr-code');
            });

        // The same three gates, plus the two a till needs: money rate limits,
        // and a replay guard that is required rather than optional.
        Route::prefix('agent')
            ->name('agent.')
            ->middleware([
                'auth:sanctum',
                'account.status.check',
                'feature.enabled:agent_program',
                'api.role:agent',
                'throttle:api-money',
                'api.idempotency',
            ])
            ->controller(MobileAgentOperationController::class)
            ->group(function () {
                Route::post('cash-in', 'cashIn')->name('cash-in');
                Route::post('cash-out', 'cashOut')->name('cash-out');
            });

        // Sending the code moves nothing, so it carries no replay guard:
        // asking again is a legitimate action when a customer did not get the
        // message. The money rate limit is what bounds it.
        Route::prefix('agent')
            ->name('agent.')
            ->middleware([
                'auth:sanctum',
                'account.status.check',
                'feature.enabled:agent_program',
                'api.role:agent',
                'throttle:api-money',
            ])
            ->controller(MobileAgentOperationController::class)
            ->group(function () {
                Route::post('cash-out/otp', 'sendCashOutOtp')->name('cash-out.otp');
            });

        /*
         * Merchant surface. Read-only here: taking payment is the existing
         * server-to-server API's job, and this is the owner looking at their
         * own business from a phone.
         */
        Route::prefix('merchant')
            ->name('merchant.')
            ->middleware([
                'auth:sanctum',
                'account.status.check',
                'feature.enabled:merchant_payment',
                'api.role:merchant',
                'throttle:api-read',
            ])
            ->controller(MobileMerchantController::class)
            ->group(function () {
                Route::get('profile', 'profile')->name('profile');
                Route::get('stats', 'stats')->name('stats');
                Route::get('transactions', 'transactions')->name('transactions');
                Route::get('payment-links', 'paymentLinks')->name('payment-links');
                Route::get('profile/{merchant}', 'show')->name('show');
                Route::get('charges/{reference}', 'chargeStatus')->name('charges.show');
            });

        // A counter charge opens a checkout the customer will pay, so it is
        // money: rate-limited as money and guarded against a double tap.
        Route::prefix('merchant')
            ->name('merchant.')
            ->middleware([
                'auth:sanctum',
                'account.status.check',
                'feature.enabled:merchant_payment',
                'api.role:merchant',
                'throttle:api-money',
                'api.idempotency',
            ])
            ->controller(MobileMerchantController::class)
            ->group(function () {
                Route::post('charge', 'charge')->name('charge');
            });

        // Calling a charge off moves no money and asking twice changes
        // nothing, so it needs no replay key - only the write limit.
        Route::prefix('merchant')
            ->name('merchant.')
            ->middleware([
                'auth:sanctum',
                'account.status.check',
                'feature.enabled:merchant_payment',
                'api.role:merchant',
                'throttle:api-write',
            ])
            ->controller(MobileMerchantController::class)
            ->group(function () {
                Route::post('charges/{reference}/cancel', 'cancelCharge')->name('charges.cancel');
                // Sends at most once per payment, so a repeat is harmless too.
                Route::post('charges/{reference}/receipt', 'sendReceipt')->name('charges.receipt');
            });

        /*
         * Subscriptions, over a token instead of the key headers.
         *
         * The controller above is reused exactly as it stands: api.merchant
         * puts the same merchant and environment attributes on the request
         * that MerchantApiAuth does, resolved from the token owner. The
         * header-authenticated routes at the top of this file are untouched -
         * a live integration must keep working byte for byte.
         */
        Route::prefix('merchant/subscriptions')
            ->name('merchant.subscriptions.')
            ->middleware([
                'auth:sanctum',
                'account.status.check',
                'api.role:merchant',
                'api.merchant',
                'cache.headers:private;no_store',
            ])
            ->controller(ApiMerchantSubscriptionController::class)
            ->group(function () {
                Route::get('/', 'index')->middleware('throttle:api-read')->name('index');
                Route::get('{subscription}', 'show')->middleware('throttle:api-read')->name('show');

                Route::middleware(['throttle:api-money', 'api.idempotency'])->group(function () {
                    Route::post('/', 'store')->name('store');
                    Route::post('{subscription}/cancel', 'cancel')->name('cancel');
                    Route::post('{subscription}/renew', 'renew')->name('renew');
                });
            });

        // Creating and editing a page, not a transfer, so no replay guard: a
        // duplicate link is an annoyance a merchant deletes, not money moved
        // twice.
        Route::prefix('merchant/payment-links')
            ->name('merchant.payment-links.')
            ->middleware([
                'auth:sanctum',
                'account.status.check',
                'feature.enabled:payment_link',
                'api.role:merchant',
                'throttle:api-write',
            ])
            ->controller(MobilePaymentLinkController::class)
            ->group(function () {
                Route::post('/', 'store')->name('store');
                Route::patch('{link}', 'update')->name('update');
                Route::post('{link}/toggle', 'toggle')->name('toggle');
                Route::delete('{link}', 'destroy')->name('destroy');
            });

        /*
         * The counter QR. Scanning and confirming belong to the CUSTOMER, so
         * these two carry no agent role gate - anybody signed in can be cashed
         * out at a counter. Only mark-paid is the agent's.
         */
        Route::prefix('agent/qr')
            ->name('agent.qr.')
            ->middleware([
                'auth:sanctum',
                'account.status.check',
                'feature.enabled:agent_program',
            ])
            ->controller(MobileAgentQrController::class)
            ->group(function () {
                Route::get('operations/{operation}', 'status')->middleware('throttle:api-read')->name('operations.status');
                Route::get('{token}', 'show')->middleware('throttle:api-read')->name('show');
                Route::post('{token}/cash-out', 'cashOut')
                    ->middleware(['throttle:api-money', 'api.idempotency'])
                    ->name('cash-out');
            });

        Route::prefix('agent')
            ->name('agent.')
            ->middleware([
                'auth:sanctum',
                'account.status.check',
                'feature.enabled:agent_program',
                'api.role:agent',
                'throttle:api-money',
                'api.idempotency',
            ])
            ->controller(MobileAgentQrController::class)
            ->group(function () {
                Route::post('operations/{operation}/mark-paid', 'markPaid')->name('operations.mark-paid');
            });

        /*
         * Exchanging between two of the customer's own wallets.
         *
         * Both gates the web form carries, in the same order: the module has
         * to be on for the installation, and the per-user switch has to be on
         * for this account. The preview is behind both too - a customer who
         * cannot exchange should be told so before they type an amount, not
         * after.
         *
         * The preview writes nothing and holds nothing, so it carries no
         * replay guard and sits on the read limit. The confirm moves money,
         * so it carries both.
         */
        Route::prefix('exchange')
            ->name('exchange.')
            ->middleware([
                'auth:sanctum',
                'account.status.check',
                'feature.enabled:exchange_money',
                'feature:exchange_money',
            ])
            ->controller(MobileExchangeController::class)
            ->group(function () {
                Route::post('preview', 'preview')
                    ->middleware('throttle:api-read')
                    ->name('preview');

                Route::post('/', 'store')
                    ->middleware(['throttle:api-money', 'api.idempotency'])
                    ->name('store');
            });

        /*
         * Taking money off the platform.
         *
         * `feature.enabled:withdraw_money` is the KYC gate as well as the
         * module switch - the catalog rule carries `requires_kyc` - so it
         * guards the catalogue and the saved accounts too, not only the
         * payout. A customer who cannot withdraw has no business being shown
         * where they could withdraw to.
         *
         * `feature:withdraw` is the per-user switch, and sits where the web
         * form has it: on the payout alone. An administrator switching one
         * customer off is stopping their payouts, not hiding the page.
         */
        Route::prefix('withdraw')
            ->name('withdraw.')
            ->middleware([
                'auth:sanctum',
                'account.status.check',
                'feature.enabled:withdraw_money',
            ])
            ->controller(MobileWithdrawController::class)
            ->group(function () {
                Route::middleware('throttle:api-read')->group(function () {
                    Route::get('methods', 'methods')->name('methods');
                    Route::get('methods/{method}/fields', 'fields')->name('methods.fields');
                    Route::get('accounts', 'accounts')->name('accounts.index');
                });

                // Saving a destination moves nothing, so no replay guard: a
                // duplicate account is a row the customer deletes.
                Route::post('accounts', 'storeAccount')
                    ->middleware('throttle:api-write')
                    ->name('accounts.store');

                Route::post('preview', 'preview')
                    ->middleware('throttle:api-read')
                    ->name('preview');

                Route::post('/', 'store')
                    ->middleware(['feature:withdraw', 'throttle:api-money', 'api.idempotency'])
                    ->name('store');
            });

        /*
         * The people a customer deals with, and what a scanned code is.
         * Reads and flags only: no money moves on this surface.
         */
        Route::middleware(['auth:sanctum', 'account.status.check'])
            ->group(function () {
                Route::controller(MobileContactController::class)->group(function () {
                    Route::get('contacts', 'index')->middleware('throttle:api-read')->name('contacts.index');
                    Route::get('contacts/{user}/between', 'between')->middleware('throttle:api-read')->name('contacts.between');
                    Route::post('contacts/match', 'match')->middleware('throttle:api-write')->name('contacts.match');
                    Route::post('contacts', 'store')->middleware('throttle:api-write')->name('contacts.store');
                    Route::patch('contacts/{user}', 'update')->middleware('throttle:api-write')->name('contacts.update');
                    Route::delete('contacts/{user}', 'destroy')->middleware('throttle:api-write')->name('contacts.destroy');
                });

                Route::get('pay/resolve', [MobilePayResolveController::class, 'resolve'])->middleware('throttle:api-read')->name('pay.resolve');
            });

        /*
         * Where a rate has been, and alerts on where it goes. The history is
         * a read; an alert is a row, and an auto-exchange alert is a standing
         * order that runs from the scheduler, so it sits on the write limit
         * behind the exchange module's own gate.
         */
        Route::middleware(['auth:sanctum', 'account.status.check'])
            ->controller(MobileRateController::class)
            ->group(function () {
                Route::get('rates/history', 'history')->middleware('throttle:api-read')->name('rates.history');
                Route::get('rates/current', 'current')->middleware('throttle:api-read')->name('rates.current');
                Route::get('rate-alerts', 'index')->middleware('throttle:api-read')->name('rate-alerts.index');
                Route::post('rate-alerts', 'store')->middleware(['feature.enabled:exchange_money', 'throttle:api-write'])->name('rate-alerts.store');
                Route::delete('rate-alerts/{alert}', 'destroy')->middleware('throttle:api-write')->name('rate-alerts.destroy');
            });
        /*
         * Sending money to another customer.
         *
         * Both gates the web form carries, and the preview is behind both:
         * a customer who cannot send should not be able to turn a phone
         * number into a name.
         *
         * The preview sits on the MONEY limit rather than the read one. It
         * writes nothing, so a replay guard would be meaningless - but it
         * does resolve a recipient and name them, and that is not something
         * a client should be able to run down a list with.
         */
        Route::prefix('send-money')
            ->name('send-money.')
            ->middleware([
                'auth:sanctum',
                'account.status.check',
                'feature.enabled:send_money',
                'feature:send_money',
                'throttle:api-money',
            ])
            ->controller(MobileSendMoneyController::class)
            ->group(function () {
                Route::post('preview', 'preview')->name('preview');

                Route::post('/', 'store')
                    ->middleware('api.idempotency')
                    ->name('store');
            });

        /*
         * Sending money abroad.
         *
         * `remittances`, plural, beside the integration API's `remittance/*`
         * and `beneficiaries` above, which answer in their own shape for the
         * integrations already parsing it and are left exactly as they are.
         *
         * Both gates on every route, the web form's pair: the module (which
         * carries the KYC rule) and the per-user switch. A customer who cannot
         * send abroad has no business pricing a transfer or saving somebody to
         * send to.
         *
         * The quote writes a locked-rate row and holds no money: the
         * remittance-quotes bucket the integration quote shares, and no
         * replay key. The send moves money: the money bucket and a key.
         *
         * Literal paths before {reference}, or they would be read as one.
         */
        Route::prefix('remittances')
            ->name('remittances.')
            ->middleware([
                'auth:sanctum',
                'account.status.check',
                'feature.enabled:remittance',
                'feature:remittance',
            ])
            ->group(function () {
                Route::controller(MobileRemittanceController::class)->group(function () {
                    Route::get('corridors', 'corridors')
                        ->middleware('throttle:api-read')
                        ->name('corridors');

                    Route::post('quote', 'quote')
                        ->middleware('throttle:remittance-quotes')
                        ->name('quote');
                });

                Route::prefix('recipients')
                    ->name('recipients.')
                    ->controller(MobileRemittanceRecipientController::class)
                    ->group(function () {
                        Route::get('/', 'index')
                            ->middleware('throttle:api-read')
                            ->name('index');

                        // Saving somebody moves nothing, so no replay key: a
                        // duplicate is a row the customer deletes.
                        Route::post('/', 'store')
                            ->middleware('throttle:api-write')
                            ->name('store');

                        Route::patch('{recipient}', 'update')
                            ->whereNumber('recipient')
                            ->middleware('throttle:api-write')
                            ->name('update');

                        Route::delete('{recipient}', 'destroy')
                            ->whereNumber('recipient')
                            ->middleware('throttle:api-write')
                            ->name('destroy');
                    });

                Route::controller(MobileRemittanceController::class)->group(function () {
                    Route::get('/', 'index')
                        ->middleware('throttle:api-read')
                        ->name('index');

                    Route::post('/', 'store')
                        ->middleware(['throttle:api-money', 'api.idempotency'])
                        ->name('store');

                    Route::get('{reference}', 'show')
                        ->middleware('throttle:api-read')
                        ->name('show');
                });
            });

        /*
         * Mass payments: one wallet paying many, from a CSV or a list.
         *
         * Not under merchant.* and without api.role: whether a panel may use
         * the module is layer A's decision (`feature.enabled:mass_payment`,
         * merchants only as shipped), and an admin can open it to others.
         * Both per-user switches gate every route, as on the website.
         *
         * Building a draft and discarding one move no money, but they write,
         * so they carry the write limit and a replay key. Confirming reserves
         * the whole total and stopping refunds what is unpaid: the money
         * limit and a replay key. The service makes a second confirm a replay
         * on its own as well, under the batch lock.
         *
         * Literal paths before the uuid parameter, or they would be read as
         * one.
         */
        Route::prefix('mass-payments')
            ->name('mass-payments.')
            ->middleware([
                'auth:sanctum',
                'account.status.check',
                'feature.enabled:mass_payment',
                'feature:send_money',
                'feature:mass_payment',
            ])
            ->controller(MobileMassPaymentController::class)
            ->group(function () {
                Route::middleware('throttle:api-read')->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::get('template', 'template')->name('template');
                    Route::get('summary', 'summary')->name('summary');
                    Route::get('{batch}', 'show')->whereUuid('batch')->name('show');
                    Route::get('{batch}/rows', 'rows')->whereUuid('batch')->name('rows');
                    Route::get('{batch}/report', 'report')->whereUuid('batch')->name('report');
                });

                // Costs one line of the hourly recipient budget per call, on
                // top of the write throttle.
                Route::post('recipients/preview', 'previewRecipient')->middleware('throttle:api-write')->name('recipients.preview');

                Route::middleware(['throttle:api-write', 'api.idempotency'])->group(function () {
                    Route::post('/', 'store')->name('store');
                    Route::post('{batch}/discard', 'discard')->whereUuid('batch')->name('discard');
                });

                Route::middleware(['throttle:api-money', 'api.idempotency'])->group(function () {
                    Route::post('{batch}/confirm', 'confirm')->whereUuid('batch')->name('confirm');
                    Route::post('{batch}/stop', 'stop')->whereUuid('batch')->name('stop');
                });
            });
        /*
         * Mass payouts: one wallet paying many external accounts through one
         * withdraw method. The same shape and limits as mass payments; layer
         * A must have both mass_payout and withdraw_money on for the panel,
         * and the per-user withdraw and mass_payout switches gate every
         * route, as on the website.
         */
        Route::prefix('mass-payouts')
            ->name('mass-payouts.')
            ->middleware([
                'auth:sanctum',
                'account.status.check',
                'feature.enabled:mass_payout',
                'feature.enabled:withdraw_money',
                'feature:withdraw',
                'feature:mass_payout',
            ])
            ->controller(MobileMassPayoutController::class)
            ->group(function () {
                Route::middleware('throttle:api-read')->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::get('methods', 'methods')->name('methods');
                    Route::get('template', 'template')->name('template');
                    Route::get('summary', 'summary')->name('summary');
                    Route::get('{batch}', 'show')->whereUuid('batch')->name('show');
                    Route::get('{batch}/rows', 'rows')->whereUuid('batch')->name('rows');
                    Route::get('{batch}/report', 'report')->whereUuid('batch')->name('report');
                });

                Route::middleware(['throttle:api-write', 'api.idempotency'])->group(function () {
                    Route::post('/', 'store')->name('store');
                    Route::post('{batch}/discard', 'discard')->whereUuid('batch')->name('discard');
                });

                Route::middleware(['throttle:api-money', 'api.idempotency'])->group(function () {
                    Route::post('{batch}/confirm', 'confirm')->whereUuid('batch')->name('confirm');
                    Route::post('{batch}/stop', 'stop')->whereUuid('batch')->name('stop');
                });
            });
        /*
         * Staking into Wallet Earn. The principal leaves the wallet for the
         * plan's term, so this carries the money limit and a replay guard: a
         * retried request must not stake twice. The catalog switch is the KYC
         * gate as well, as on the website.
         */
        Route::prefix('earn')
            ->name('earn.')
            ->middleware([
                'auth:sanctum',
                'account.status.check',
                'feature.enabled:wallet_earn',
                'throttle:api-money',
                'api.idempotency',
            ])
            ->controller(MobileWalletEarnController::class)
            ->group(function () {
                Route::post('stakes', 'store')->name('stakes.store');
                Route::patch('stakes/{stake}', 'update')->withoutMiddleware('api.idempotency')->name('stakes.update');
            });
        /*
         * Claiming cashback credits a wallet: the money limit and a replay
         * guard, and the claim row itself answers a second claim of the same
         * month with the first.
         */
        Route::post('rewards/cashback/claim', [MobileRewardsController::class, 'claimCashback'])
            ->middleware(['auth:sanctum', 'account.status.check', 'throttle:api-money', 'api.idempotency'])
            ->name('rewards.cashback.claim');
        /*
         * Making a voucher debits a wallet; redeeming one credits. Both carry
         * the money limit and a required replay key - a retried redeem is
         * answered with the first answer, and VoucherService's claim makes a
         * second request for the same code credit nothing.
         */
        Route::prefix('vouchers')
            ->name('vouchers.')
            ->middleware([
                'auth:sanctum',
                'account.status.check',
                'feature.enabled:vouchers',
                'throttle:api-money',
                'api.idempotency',
            ])
            ->controller(MobileVoucherController::class)
            ->group(function () {
                Route::post('/', 'store')->name('store');
                Route::post('redeem', 'redeem')->name('redeem');
            });
        /*
         * Recharging a phone calls an external provider, so the replay key is
         * what stands between a retried request and a second top-up: the
         * first answer is handed back and the provider is not called again.
         * The catalog switch carries the phone-verification rule.
         */
        Route::post('mobile-recharge', [MobileRechargeApiController::class, 'store'])
            ->middleware(['auth:sanctum', 'account.status.check', 'feature.enabled:mobile_recharge', 'throttle:api-money', 'api.idempotency'])
            ->name('mobile-recharge.store');

        /*
         * Paying a bill debits the wallet before an operator or a provider
         * pays the biller, so the replay key carries the weight: a retried
         * request is handed the first answer, and BillPaymentService keeps
         * its own copy of the key so a retry the middleware let through
         * still finds the payment already made. One key is one debit.
         */
        Route::post('bill-payments', [BillPaymentApiController::class, 'store'])
            ->middleware(['auth:sanctum', 'account.status.check', 'feature.enabled:bill_payment', 'throttle:api-money', 'api.idempotency'])
            ->name('bill-payments.store');

        /*
         * Asking a biller's provider whether an account exists moves no money
         * and reserves nothing, so it carries no replay key; its own throttle
         * keeps a customer from walking the provider's account numbers, under
         * its own prefix so it never shares a counter with the other numeric
         * throttles.
         */
        Route::post('bill-payments/billers/{biller}/verify-customer', [BillPaymentApiController::class, 'verifyCustomer'])
            ->whereNumber('biller')
            ->middleware(['auth:sanctum', 'account.status.check', 'feature.enabled:bill_payment', 'throttle:20,1,bill-payment-verify'])
            ->name('bill-payments.verify-customer');

        /*
         * Forgetting one of "My bills" moves no money and is the same answer
         * however often it is sent, so it carries the write limit and no
         * replay key. Only ever reached through the caller's own bills.
         */
        Route::delete('bill-payments/saved/{savedBill}', [BillPaymentApiController::class, 'forgetSaved'])
            ->whereNumber('savedBill')
            ->middleware(['auth:sanctum', 'account.status.check', 'feature.enabled:bill_payment', 'throttle:api-write'])
            ->name('bill-payments.saved.destroy');

        // BP3: the monthly reminder on one of "My bills" - set or cleared.
        // Moves no money, so the write limit and no replay key, as above.
        Route::patch('bill-payments/saved/{savedBill}', [BillPaymentApiController::class, 'setSavedReminder'])
            ->whereNumber('savedBill')
            ->middleware(['auth:sanctum', 'account.status.check', 'feature.enabled:bill_payment', 'throttle:api-write'])
            ->name('bill-payments.saved.reminder');

        /*
         * The prepaid token sent to the customer again, where the admin lets
         * it leave the receipt. Each one is an SMS or a mail, so a few an
         * hour, under its own prefix; the receipt itself always shows it.
         */
        Route::post('bill-payments/{billPayment}/resend-token', [BillPaymentApiController::class, 'resendToken'])
            ->whereUuid('billPayment')
            ->middleware(['auth:sanctum', 'account.status.check', 'feature.enabled:bill_payment', 'throttle:3,60,bill-payment-token'])
            ->name('bill-payments.resend-token');

        /*
         * Taking a trade ad holds the seller's escrow; each step after it moves
         * or frees it. All carry the money limit and a required replay key - a
         * retried release is answered with the first answer, and the service
         * refuses a second release on its own.
         */
        Route::prefix('p2p/orders')
            ->name('p2p.orders.')
            ->middleware(['auth:sanctum', 'account.status.check', 'p2p.enabled', 'feature.enabled:p2p_marketplace', 'p2p.country', 'throttle:api-money', 'api.idempotency'])
            ->controller(MobileP2POrderController::class)
            ->group(function () {
                Route::post('/', 'store')->name('store');
                Route::post('{order}/paid', 'markPaid')->name('paid');
                Route::post('{order}/release', 'release')->name('release');
                Route::post('{order}/cancel', 'cancel')->name('cancel');
            });

        /*
         * Putting money on a card and taking it off. The money limit and a
         * required replay key; the top-up also hands the key to the card
         * manager as its own funding key, so a retry funds the card once.
         */
        Route::prefix('virtual-cards/{card}')
            ->name('virtual-cards.')
            ->middleware(['auth:sanctum', 'account.status.check', 'feature.enabled:virtual_card', 'throttle:api-money', 'api.idempotency'])
            ->controller(MobileVirtualCardController::class)
            ->group(function () {
                Route::post('topup', 'topup')->name('topup');
                Route::post('withdraw', 'withdraw')->name('withdraw');
            });

        /*
         * The full card number and CVV, behind the wallet PIN. On the PIN
         * limit, since a wrong PIN here is the same guess as anywhere else.
         */
        Route::post('virtual-cards/{card}/reveal', [MobileVirtualCardController::class, 'reveal'])
            ->middleware(['auth:sanctum', 'account.status.check', 'feature.enabled:virtual_card', 'throttle:api-auth'])
            ->name('virtual-cards.reveal');

        /*
         * Freezing a card, and the cardholder's own limits and controls: no
         * money moves, so writes. The catalog switch carries the KYC rule.
         */
        Route::prefix('virtual-cards/{card}')
            ->name('virtual-cards.')
            ->middleware(['auth:sanctum', 'account.status.check', 'feature.enabled:virtual_card', 'throttle:api-write'])
            ->controller(MobileVirtualCardController::class)
            ->group(function () {
                Route::post('freeze', 'freeze')->name('freeze');
                Route::post('unfreeze', 'unfreeze')->name('unfreeze');
                Route::put('limits', 'limits')->name('limits');
                Route::put('controls', 'controls')->name('controls');
                Route::post('replace', 'replace')->name('replace');
            });

        // Applying to be a cardholder moves no money: a write, behind the card feature's gate.
        Route::post('virtual-cards/cardholders', [MobileVirtualCardController::class, 'applyCardholder'])
            ->middleware(['auth:sanctum', 'account.status.check', 'feature.enabled:virtual_card', 'throttle:api-write'])
            ->name('virtual-cards.cardholders.store');

        /*
         * Asking for a virtual card debits nothing yet, but an approval does:
         * the money limit and a required replay key, so a retry files one
         * request. The catalog switch carries the KYC rule.
         */
        Route::post('virtual-cards/requests', [MobileVirtualCardController::class, 'requestCard'])
            ->middleware(['auth:sanctum', 'account.status.check', 'feature.enabled:virtual_card', 'throttle:api-money', 'api.idempotency'])
            ->name('virtual-cards.requests.store');

        /*
         * Reviewing a finished trade and disputing an open one move no money:
         * writes. The service refuses a second review or dispute on its own.
         */
        Route::prefix('p2p/orders')
            ->name('p2p.orders.')
            ->middleware(['auth:sanctum', 'account.status.check', 'p2p.enabled', 'feature.enabled:p2p_marketplace', 'p2p.country', 'throttle:api-write'])
            ->controller(MobileP2POrderController::class)
            ->group(function () {
                Route::post('{order}/review', 'review')->name('review');
                Route::post('{order}/messages', 'send')->name('messages.store');
                Route::post('{order}/dispute', 'dispute')->name('dispute');
            });

        /*
         * Promoting a trade ad. The packages and a quote are reads; the
         * purchase debits a wallet - the money limit and a required replay key.
         */
        Route::prefix('p2p/offers/{offer}/promotion')
            ->name('p2p.offers.promotion.')
            ->middleware(['auth:sanctum', 'account.status.check', 'p2p.enabled', 'feature.enabled:p2p_marketplace', 'p2p.country'])
            ->controller(MobileP2POfferPromotionController::class)
            ->group(function () {
                Route::get('/', 'show')->middleware('throttle:api-read')->name('show');
                Route::post('quote', 'quote')->middleware('throttle:api-read')->name('quote');
                Route::post('/', 'purchase')->middleware(['throttle:api-money', 'api.idempotency'])->name('purchase');
            });

        /*
         * Publishing, pausing and withdrawing a P2P trade ad moves no money:
         * writes, behind the website's P2P gates.
         */
        Route::prefix('p2p/offers')
            ->name('p2p.offers.')
            ->middleware(['auth:sanctum', 'account.status.check', 'p2p.enabled', 'feature.enabled:p2p_marketplace', 'p2p.country', 'throttle:api-write'])
            ->controller(MobileP2POfferController::class)
            ->group(function () {
                Route::post('/', 'store')->name('store');
                Route::post('{offer}/toggle', 'toggle')->name('toggle');
                Route::patch('{offer}', 'update')->name('update');
                Route::delete('{offer}', 'destroy')->name('destroy');
            });

        // Blocking a trader hides their ads from one customer: a write, behind the same gates.
        Route::prefix('p2p/traders/{user}')
            ->name('p2p.traders.')
            ->middleware(['auth:sanctum', 'account.status.check', 'p2p.enabled', 'feature.enabled:p2p_marketplace', 'p2p.country', 'throttle:api-write'])
            ->controller(MobileP2POfferController::class)
            ->group(function () {
                Route::post('block', 'block')->name('block');
                Route::delete('block', 'unblock')->name('unblock');
            });

        // A saved market search moves nothing: a write, behind the same gates.
        Route::prefix('p2p/market-alerts')
            ->name('p2p.market-alerts.')
            ->middleware(['auth:sanctum', 'account.status.check', 'p2p.enabled', 'feature.enabled:p2p_marketplace', 'p2p.country', 'throttle:api-write'])
            ->controller(MobileP2PMarketController::class)
            ->group(function () {
                Route::post('/', 'storeAlert')->name('store');
                Route::delete('{alert}', 'destroyAlert')->name('destroy');
            });

        /*
         * A P2P payment account is where a buyer pays the customer. Saving one
         * moves no money, so these are writes, behind the website's P2P gates.
         */
        Route::prefix('p2p/payment-accounts')
            ->name('p2p.payment-accounts.')
            ->middleware(['auth:sanctum', 'account.status.check', 'p2p.enabled', 'feature.enabled:p2p_marketplace', 'p2p.country', 'throttle:api-write'])
            ->controller(MobileP2PPaymentAccountController::class)
            ->group(function () {
                Route::post('/', 'store')->name('store');
                Route::put('{account}', 'update')->name('update');
                Route::delete('{account}', 'destroy')->name('destroy');
            });

        /*
         * Paying a vendor moves money out of a wallet - the money limit and a
         * required replay key. Applying to sell moves none, so it is a write.
         */
        Route::prefix('marketplace')
            ->name('marketplace.')
            ->middleware(['auth:sanctum', 'account.status.check', 'feature.enabled:vendor_marketplace'])
            ->controller(MobileMarketplaceController::class)
            ->group(function () {
                Route::post('vendors/{vendor}/pay', 'pay')
                    ->middleware(['throttle:api-money', 'api.idempotency'])
                    ->name('vendors.pay');
                Route::post('apply', 'apply')
                    ->middleware('throttle:api-write')
                    ->name('apply');
            });
        /*
         * Buying a gift card debits a wallet; redeeming one credits; cancelling
         * one refunds its sender. The money limit and a required replay key
         * on each, as for vouchers.
         */
        Route::prefix('gift-cards')
            ->name('gift-cards.')
            ->middleware([
                'auth:sanctum',
                'account.status.check',
                'feature.enabled:gift_cards',
                'throttle:api-money',
                'api.idempotency',
            ])
            ->controller(MobileGiftCardController::class)
            ->group(function () {
                Route::post('/', 'store')->name('store');
                Route::post('redeem', 'redeem')->name('redeem');
                Route::post('{card}/cancel', 'cancel')->whereNumber('card')->name('cancel');
            });
        /*
         * Putting money in.
         *
         * The catalogue is a read: it names the ways in, what they cost and
         * what a manual one wants told. Filing the deposit is on the money
         * limit with a replay guard, because a duplicate is a second pending
         * row an administrator then has to tell apart from a real one - and on
         * an automatic method it is a second invoice at the gateway.
         */
        Route::prefix('deposit')
            ->name('deposit.')
            ->middleware([
                'auth:sanctum',
                'account.status.check',
                'feature.enabled:deposit_money',
            ])
            ->controller(MobileDepositController::class)
            ->group(function () {
                Route::get('methods', 'methods')
                    ->middleware('throttle:api-read')
                    ->name('methods');

                Route::post('/', 'store')
                    ->middleware(['feature:deposit', 'throttle:api-money', 'api.idempotency'])
                    ->name('store');

                // Proof for a deposit already filed moves nothing.
                Route::post('{transaction}/proof', 'proof')
                    ->middleware(['feature:deposit', 'throttle:api-write'])
                    ->name('proof');
            });
        /*
         * Asking another customer for money, and answering.
         *
         * Asking moves nothing, so it carries no replay guard - a duplicate
         * request is an annoyance the requester withdraws. Approving DOES
         * move money, so it carries both the money limit and the guard.
         *
         * Declining is on the write limit: it moves nothing, and a customer
         * refusing a request twice has simply tapped twice.
         *
         * `feature:request_money` sits where the web form has it - on asking.
         * Answering a request already made is not something a per-user switch
         * should strand: a customer switched off mid-request must still be
         * able to decline what is pending against them.
         */
        // Round 7: moving money into or out of a vault, and creating or
        // paying a scheduled payment now, are money moves - rate-limited
        // and replay-guarded like every other.
        Route::prefix('vaults')
            ->name('vaults.')
            ->middleware(['auth:sanctum', 'account.status.check', 'feature.enabled:vaults', 'throttle:api-money', 'api.idempotency'])
            ->controller(MobileVaultController::class)
            ->group(function () {
                Route::post('{vault}/deposit', 'deposit')->name('deposit');
                Route::post('{vault}/withdraw', 'withdraw')->name('withdraw');
                Route::post('{vault}/close', 'close')->name('close');
            });
        Route::prefix('scheduled')
            ->name('scheduled.')
            ->middleware(['auth:sanctum', 'account.status.check', 'feature.enabled:scheduled_payments', 'throttle:api-money', 'api.idempotency'])
            ->controller(MobileScheduledPaymentController::class)
            ->group(function () {
                Route::post('/', 'store')->name('store');
                Route::post('{schedule}/pay-now', 'payNow')->name('pay-now');
            });

        Route::prefix('request-money')
            ->name('request-money.')
            ->middleware([
                'auth:sanctum',
                'account.status.check',
                'feature.enabled:request_money',
            ])
            ->controller(MobileRequestMoneyController::class)
            ->group(function () {
                Route::get('/', 'index')
                    ->middleware('throttle:api-read')
                    ->name('index');

                Route::post('/', 'store')
                    ->middleware(['feature:request_money', 'throttle:api-money'])
                    ->name('store');

                Route::post('{reference}/approve', 'approve')
                    ->middleware(['throttle:api-money', 'api.idempotency'])
                    ->name('approve');

                Route::post('{reference}/decline', 'decline')
                    ->middleware('throttle:api-write')
                    ->name('decline');

                // One bill to several people: the same per-request write,
                // repeated inside one transaction.
                Route::post('split', 'split')
                    ->middleware(['feature:request_money', 'throttle:api-money', 'api.idempotency'])
                    ->name('split');

                // A nudge moves nothing.
                Route::post('{reference}/remind', 'remind')
                    ->middleware('throttle:api-write')
                    ->name('remind');
            });
        /*
         * Paying a merchant - the CUSTOMER's side of a counter QR, which is
         * why this sits outside the merchant/ prefix and carries no
         * api.role:merchant. Anybody with a wallet can be asked to pay.
         *
         * `feature.enabled:merchant_payment` is the module switch and the KYC,
         * phone and country conditions; the service applies them again, so a
         * caller that reaches it another way is held to the same rule.
         */
        Route::prefix('merchant-payments')
            ->name('merchant-payments.')
            ->middleware([
                'auth:sanctum',
                'account.status.check',
                'feature.enabled:merchant_payment',
            ])
            ->controller(MobileMerchantPaymentController::class)
            ->group(function () {
                Route::get('{reference}', 'show')
                    ->middleware('throttle:api-read')
                    ->name('show');

                Route::post('{reference}/pay', 'pay')
                    ->middleware(['throttle:api-money', 'api.idempotency'])
                    ->name('pay');
            });
        Route::middleware(['auth:sanctum', 'account.status.check', 'throttle:api-write'])->group(function () {
            Route::post('notifications/read-all', [MobileNotificationController::class, 'markAllAsRead'])->name('notifications.read-all');
            Route::post('notifications/{notification}/read', [MobileNotificationController::class, 'markAsRead'])->name('notifications.read');
            Route::delete('notifications/{notification}', [MobileNotificationController::class, 'destroy'])->name('notifications.destroy');
            Route::put('notification-preferences', [MobileNotificationPreferencesController::class, 'update'])->name('notification-preferences.update');
            Route::post('mobile-recharge/saved', [MobileRechargeApiController::class, 'saveNumber'])->middleware('feature.enabled:mobile_recharge')->name('mobile-recharge.saved.store');
            Route::delete('mobile-recharge/saved/{number}', [MobileRechargeApiController::class, 'forgetNumber'])->middleware('feature.enabled:mobile_recharge')->name('mobile-recharge.saved.destroy');
            Route::patch('transactions/{transaction}/category', [MobileTransactionController::class, 'updateCategory'])->name('transactions.category');
            Route::middleware('feature.enabled:budgets')->prefix('budgets')->as('budgets.')->controller(MobileBudgetController::class)->group(function () {
                Route::post('/', 'store')->name('store');
                Route::patch('{budget}', 'update')->name('update');
                Route::delete('{budget}', 'destroy')->name('destroy');
            });
            Route::middleware('feature.enabled:vaults')->prefix('vaults')->as('vaults.')->controller(MobileVaultController::class)->group(function () {
                Route::post('/', 'store')->name('store');
                Route::patch('{vault}', 'update')->name('update');
            });
            Route::middleware('feature.enabled:scheduled_payments')->prefix('scheduled')->as('scheduled.')->controller(MobileScheduledPaymentController::class)->group(function () {
                Route::post('preview', 'preview')->name('preview');
                Route::patch('{schedule}', 'update')->name('update');
                Route::post('{schedule}/pause', 'pause')->name('pause');
                Route::post('{schedule}/resume', 'resume')->name('resume');
                Route::post('{schedule}/skip-next', 'skipNext')->name('skip-next');
                Route::delete('{schedule}', 'destroy')->name('destroy');
            });

            Route::post('wallets', [MobileWalletController::class, 'store'])->name('wallets.store');
            Route::patch('wallets/{wallet}', [MobileWalletController::class, 'update'])->name('wallets.update');
            Route::patch('wallets/{wallet}/status', [MobileWalletController::class, 'status'])->name('wallets.status');
            Route::post('wallets/{wallet}/receive-link', [MobileWalletController::class, 'receiveLink'])->name('wallets.receive-link');
            Route::post('transactions/{transaction}/notify', [MobileTransactionController::class, 'notify'])->name('transactions.notify');
            Route::patch('me', [MobileProfileController::class, 'update'])->name('me.update');
            Route::post('me/avatar', [MobileProfileController::class, 'uploadAvatar'])->name('me.avatar');
        });

        /*
         * On the sign-in bucket, not the write bucket. old_password makes this
         * a password oracle: a stolen token - and tokens never expire - could
         * otherwise test thirty guesses a minute against the real password,
         * three times what the sign-in form allows.
         */
        Route::post('me/password', [MobileProfileController::class, 'changePassword'])
            ->middleware(['auth:sanctum', 'account.status.check', 'throttle:api-auth'])
            ->name('me.password');

        /*
         * The security centre's actions. Signing devices out and the
         * lockdown revoke tokens, so they sit on the sign-in bucket; saying
         * "that was me" is an ordinary write.
         */
        Route::middleware(['auth:sanctum', 'account.status.check', 'throttle:api-auth'])
            ->controller(MobileSecurityController::class)
            ->group(function () {
                Route::delete('me/sessions', 'revokeOthers')->name('me.sessions.revoke-others');
                Route::delete('me/sessions/{device}', [MobileDeviceController::class, 'destroy'])->name('me.sessions.destroy');
                Route::post('me/lockdown', 'lockdown')->name('me.lockdown');
            });
        Route::post('me/security/logins/{login}/acknowledge', [MobileSecurityController::class, 'acknowledge'])
            ->middleware(['auth:sanctum', 'account.status.check', 'throttle:api-write'])
            ->name('me.security.acknowledge');

        /*
         * Closing the account: the read says what blocks it, the close checks
         * the password (and the code), so it is on the sign-in bucket too.
         */
        Route::get('me/closure', [MobileProfileController::class, 'closure'])
            ->middleware(['auth:sanctum', 'account.status.check', 'throttle:api-read'])
            ->name('me.closure');
        Route::post('me/close', [MobileProfileController::class, 'close'])
            ->middleware(['auth:sanctum', 'account.status.check', 'throttle:api-auth'])
            ->name('me.close');

        /*
         * The sign-in bucket again, for the same reason as me/password: setting
         * or changing the PIN checks the current PIN or the password, and the
         * reset sends an email. WalletPinGuard's counter is the real limit on
         * guessing; this keeps a token from hammering it.
         */
        Route::middleware(['auth:sanctum', 'account.status.check', 'throttle:api-auth'])
            ->controller(MobileWalletPinController::class)
            ->group(function () {
                Route::post('me/wallet-pin', 'update')->name('me.wallet-pin.update');
                Route::post('me/wallet-pin/reset', 'reset')->name('me.wallet-pin.reset');
            });

        /*
         * Setup and disable check the password and enable checks a code, so
         * all three are on the sign-in bucket for the same reason as
         * me/password.
         */
        Route::middleware(['auth:sanctum', 'account.status.check', 'throttle:api-auth'])
            ->controller(MobileTwoFactorController::class)
            ->group(function () {
                Route::post('me/2fa/setup', 'setup')->name('me.2fa.setup');
                Route::post('me/2fa/enable', 'enable')->name('me.2fa.enable');
                Route::post('me/2fa/disable', 'disable')->name('me.2fa.disable');
            });

        /*
         * Not the money bucket: nothing here moves a balance, and a double tap
         * is answered by KycSubmissionService's one-active-submission rule
         * rather than by an idempotency key.
         */
        Route::middleware(['auth:sanctum', 'account.status.check', 'throttle:api-write'])
            ->controller(MobileKycController::class)
            ->group(function () {
                Route::post('kyc', 'submit')->name('kyc.submit');
                Route::post('kyc/hosted-session', 'startHosted')->name('kyc.hosted-session');
            });

        Route::middleware(['auth:sanctum', 'account.status.check', 'throttle:api-write'])
            ->controller(MobileTicketController::class)
            ->group(function () {
                Route::post('tickets', 'store')->name('tickets.store');
                Route::post('tickets/{ticket}/reply', 'reply')->name('tickets.reply');
                Route::post('tickets/{ticket}/close', 'close')->name('tickets.close');
                Route::post('tickets/{ticket}/reopen', 'reopen')->name('tickets.reopen');
            });
    });
