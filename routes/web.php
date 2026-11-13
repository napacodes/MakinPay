<?php

use App\Http\Controllers\Api\ApiDocsController;
use App\Http\Controllers\Backend\ObservabilityWebhookController;
use App\Http\Controllers\Common\AppController;
use App\Http\Controllers\Common\FileController;
use App\Http\Controllers\Common\LocaleController;
use App\Http\Controllers\Common\SummernoteController;
use App\Http\Controllers\Frontend\AgentController;
use App\Http\Controllers\Frontend\AgentOperationController;
use App\Http\Controllers\Frontend\BeneficiaryController;
use App\Http\Controllers\Frontend\BillPaymentController;
use App\Http\Controllers\Frontend\BlogController;
use App\Http\Controllers\Frontend\CardholdersController;
use App\Http\Controllers\Frontend\CashmaalRedirectController;
use App\Http\Controllers\Frontend\ContactController;
use App\Http\Controllers\Frontend\DashboardController;
use App\Http\Controllers\Frontend\DemoDisclosureController;
use App\Http\Controllers\Frontend\DepositController;
use App\Http\Controllers\Frontend\ExchangeMoneyController;
use App\Http\Controllers\Frontend\GiftCardController;
use App\Http\Controllers\Frontend\HomeController;
use App\Http\Controllers\Frontend\IPNController;
use App\Http\Controllers\Frontend\KycSubmissionController;
use App\Http\Controllers\Frontend\Marketplace\MarketplaceController;
use App\Http\Controllers\Frontend\Marketplace\VendorStripeConnectController;
use App\Http\Controllers\Frontend\MassPaymentController;
use App\Http\Controllers\Frontend\MassPaymentScheduleController;
use App\Http\Controllers\Frontend\MassPayoutController;
use App\Http\Controllers\Frontend\MerchantController;
use App\Http\Controllers\Frontend\MerchantPaymentReceiveController;
use App\Http\Controllers\Frontend\MobileMoneyPaymentController;
use App\Http\Controllers\Frontend\MobileRechargeController;
use App\Http\Controllers\Frontend\NotificationController;
use App\Http\Controllers\Frontend\P2P\AdvertiserController;
use App\Http\Controllers\Frontend\P2P\DisputeController;
use App\Http\Controllers\Frontend\P2P\OfferController;
use App\Http\Controllers\Frontend\P2P\OfferPromotionController;
use App\Http\Controllers\Frontend\P2P\OrderChatController;
use App\Http\Controllers\Frontend\P2P\OrderController;
use App\Http\Controllers\Frontend\P2P\PaymentAccountController;
use App\Http\Controllers\Frontend\PageController;
use App\Http\Controllers\Frontend\PaymentLinkCheckoutController;
use App\Http\Controllers\Frontend\PaymentLinkController;
use App\Http\Controllers\Frontend\PhoneVerificationController;
use App\Http\Controllers\Frontend\PushSubscriptionController;
use App\Http\Controllers\Frontend\QuickMenuController;
use App\Http\Controllers\Frontend\ReferralController;
use App\Http\Controllers\Frontend\RemittanceController;
use App\Http\Controllers\Frontend\RequestMoneyController;
use App\Http\Controllers\Frontend\SecurityController;
use App\Http\Controllers\Frontend\SendMoneyController;
use App\Http\Controllers\Frontend\SettingController;
use App\Http\Controllers\Frontend\SignupBonusController;
use App\Http\Controllers\Frontend\StatusController;
use App\Http\Controllers\Frontend\SubscriberController;
use App\Http\Controllers\Frontend\SubscriptionController;
use App\Http\Controllers\Frontend\ThemePreviewController;
use App\Http\Controllers\Frontend\TicketController;
use App\Http\Controllers\Frontend\TransactionController;
use App\Http\Controllers\Frontend\TwoFactorController;
use App\Http\Controllers\Frontend\UserRankController;
use App\Http\Controllers\Frontend\VirtualCardController;
use App\Http\Controllers\Frontend\VirtualCardRequestController;
use App\Http\Controllers\Frontend\VoucherController;
use App\Http\Controllers\Frontend\WalletController;
use App\Http\Controllers\Frontend\WalletEarnController;
use App\Http\Controllers\Frontend\WalletPinController;
use App\Http\Controllers\Frontend\WithdrawAccountController;
use App\Http\Controllers\Frontend\WithdrawController;
use App\Http\Controllers\Webhook\BillPaymentWebhookController;
use App\Http\Controllers\Webhook\KycWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Landing Page Routes
|--------------------------------------------------------------------------
*/
Route::get('/', HomeController::class)->name('home');
Route::get('theme-preview/{theme}', ThemePreviewController::class)->name('theme.preview');

// Redirect /home to /
Route::redirect('/home', '/');

// Blog Routes
Route::prefix('blog')->as('blog.')->controller(BlogController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/{slug}', 'details')->name('details');
});

// Contact Routes
Route::post('/contact-submit', [ContactController::class, 'submit'])->name('contact.submit');

// Subscribe
Route::post('/subscribe', SubscriberController::class)->name('subscribe.submit');

// Public Gift Card preview (recipient lands here from email link)
Route::get('/gift-card/{code}', [GiftCardController::class, 'preview'])->name('gift-card.preview');

/*
|--------------------------------------------------------------------------
| All Type User Routes Like Normal User, Merchant User
|--------------------------------------------------------------------------
*/
/* `block.ip` is no longer listed here: BlockIp runs for the whole `web` group
   from bootstrap/app.php, so a denied address is refused on the sign-in page
   too rather than only once it is already inside. */
Route::prefix('user')->as('user.')->middleware(['auth', 'auth.session', 'account.status.check', 'verified.when.required', '2fa', 'password.change.required'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::post('/signup-bonus/acknowledge', [SignupBonusController::class, 'acknowledge'])->name('signup-bonus.acknowledge');

    // Personal quick-menu shortcuts (the apps panel in the dashboard header)
    Route::put('/quick-menu', [QuickMenuController::class, 'update'])->middleware('throttle:30,1')->name('quick-menu.update');
    Route::delete('/quick-menu', [QuickMenuController::class, 'destroy'])->middleware('throttle:30,1')->name('quick-menu.reset');

    // ========================== User Settings Routes =============================
    Route::prefix('settings')->as('settings.')->controller(SettingController::class)->group(function () {
        Route::get('profile', 'profile')->name('profile');
        Route::post('profile-update', 'profileUpdate')->name('profile.update');
        Route::get('change-password', 'changePassword')->name('password.change');
        Route::post('password-update', 'passwordUpdate')->name('password.update');
        Route::get('verify-email', 'verifyEmail')->name('verify-email');
        Route::get('subscription-status', 'subscriptionStatus')->middleware('feature.enabled:subscription_system')->name('subscription.status');
        Route::get('wallet-earn-status', 'walletEarnStatus')->name('wallet-earn.status');
        Route::get('verify-phone', [PhoneVerificationController::class, 'show'])->name('phone.verify');
        Route::post('verify-phone/send', [PhoneVerificationController::class, 'send'])
            ->middleware('throttle:3,1')
            ->name('phone.send');
        Route::post('verify-phone', [PhoneVerificationController::class, 'verify'])
            ->middleware('throttle:10,1')
            ->name('phone.confirm');
        Route::post('verify-phone/disable', [PhoneVerificationController::class, 'disable'])
            ->middleware('throttle:6,1')
            ->name('phone.disable');
        Route::get('security', [SecurityController::class, 'index'])->name('security.index');
        Route::post('security/logout-other-sessions', [SecurityController::class, 'logoutOtherSessions'])
            ->middleware('throttle:6,1')
            ->name('security.logout-other-sessions');
        Route::post('security/close-account', [SecurityController::class, 'closeAccount'])
            ->middleware('throttle:6,1')
            ->name('security.close-account');

        // Two-Factor Authentication
        Route::prefix('2fa')->as('2fa.')->controller(TwoFactorController::class)->group(function () {
            Route::get('setup', 'showSetupForm')->name('setup');
            Route::post('enable', 'enable2fa')->name('enable');
            Route::post('disable', 'disable2fa')->name('disable');
        });

        // Wallet PIN
        Route::controller(WalletPinController::class)->group(function () {
            Route::get('wallet-pin', 'form')->name('wallet-pin');
            Route::post('wallet-pin', 'update')->name('wallet-pin.update');
            Route::post('wallet-pin/reset', 'reset')->name('wallet-pin.reset');
            Route::get('wallet-pin/reset/{user}/confirm', 'confirmReset')
                ->name('wallet-pin.reset.confirm');
        });

        // KYC Verification
        Route::prefix('kyc')->as('kyc.')->controller(KycSubmissionController::class)->group(function () {
            Route::get('verify', 'kycVerify')->name('verify');
            Route::get('template/details/{id}', 'templateDetails')->name('template.details');

            Route::post('submit', 'kycSubmit')->name('submit');
            // Handover to a provider-hosted capture flow, when one covers the
            // applicant's country and automation is on.
            Route::post('start', 'startHosted')->name('start');
        });
    });

    // ========================== Wallet Routes =============================
    Route::prefix('wallet')->as('wallet.')->controller(WalletController::class)->group(function () {
        Route::get('list', 'index')->name('index');
        Route::get('my-qr-code', 'myQrCode')->name('my-qr-code');
        Route::post('create', 'create')->name('create');
        Route::get('currency-info/{currency_id}', 'currencyInfo')->name('currency-info');
        Route::post('status', 'status')->name('status');

        // json response
        Route::get('supported-payment-methods/{wallet_id}', 'supportedPaymentMethods')->name('supported-payment-methods');
        Route::get('info/{role}/{wallet_id}', 'getWalletInfo')->name('info');
        Route::get('validate-recipient/{role}/{emailOrWalletId}', 'validateRecipient')->name('validate.recipient');
    });

    // ========================== Wallet Earn Routes =============================
    Route::prefix('wallet-earn')->as('wallet-earn.')->controller(WalletEarnController::class)->middleware(['feature.enabled:wallet_earn', 'prevent.duplicate'])->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('plans', 'plans')->name('plans');
        Route::get('my-stakes', 'stakes')->name('stakes');
        Route::post('stake', 'store')->name('store');
        Route::get('stakes/{stake}', 'show')->name('show');
    });

    // ========================== Mobile Recharge Routes =============================
    Route::prefix('mobile-recharge')->as('mobile-recharge.')->controller(MobileRechargeController::class)->middleware(['feature.enabled:mobile_recharge', 'prevent.duplicate'])->group(function () {
        Route::get('create', 'create')->name('create');
        Route::post('store', 'store')->name('store');
    });

    // ========================== Bill Payment Routes =============================
    Route::prefix('bill-payments')->as('bill-payment.')->controller(BillPaymentController::class)->middleware(['feature.enabled:bill_payment', 'prevent.duplicate'])->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('history', 'history')->name('history');
        Route::get('category/{category:code}', 'billers')->name('billers');
        Route::get('pay/{biller}', 'create')->whereNumber('biller')->name('create');
        Route::get('pay/{biller}/quote', 'quote')->whereNumber('biller')->middleware('throttle:60,1')->name('quote');
        // Read-only and asked again on every blur of the account field, so
        // the five-second duplicate lock would only refuse a repeated check.
        // The throttle has its own prefix: plain numeric throttles share one
        // counter per user, and these checks must not use up the store limit.
        Route::post('pay/{biller}/verify-customer', 'verifyCustomer')->whereNumber('biller')->middleware('throttle:20,1,bill-payment-verify')->withoutMiddleware('prevent.duplicate')->name('verify-customer');
        Route::post('pay/{biller}', 'store')->whereNumber('biller')->middleware('throttle:10,1')->name('store');
        Route::delete('saved/{savedBill}', 'forgetSaved')->whereNumber('savedBill')->name('saved.destroy');
        // BP3: a monthly reminder on one of My bills. Moves no money.
        Route::post('saved/{savedBill}/reminder', 'setSavedReminder')->whereNumber('savedBill')->name('saved.reminder');
        Route::get('{billPayment}', 'show')->whereUuid('billPayment')->name('show');
        // The prepaid token sent again, where the admin lets it leave the
        // receipt: a few times an hour, since each one is an SMS or a mail.
        Route::post('{billPayment}/resend-token', 'resendToken')->whereUuid('billPayment')->middleware('throttle:3,60,bill-payment-token')->name('resend-token');
    });

    // ========================== Mass Payment Routes =============================
    // Layer A owns the panel (merchants only by default); both per-user
    // switches must be on as well. Every batch is read through the owner's
    // own relation, so another customer's uuid is a 404. Each POST is guarded
    // by the duplicate lock and its own throttle prefix, so a burst here
    // cannot spend another screen's allowance.
    Route::prefix('mass-payments')->as('mass-payment.')->controller(MassPaymentController::class)->middleware(['feature.enabled:mass_payment', 'feature:send_money', 'feature:mass_payment'])->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('create', 'create')->name('create');
        Route::get('template.csv', 'template')->name('template');
        Route::post('/', 'store')->middleware(['prevent.duplicate', 'throttle:10,60,mass-payment-draft'])->name('store');
        Route::get('{batch}', 'show')->whereUuid('batch')->name('show');
        Route::get('{batch}/status', 'status')->whereUuid('batch')->middleware('throttle:120,1,mass-payment-status')->name('status');
        Route::post('{batch}/confirm', 'confirm')->whereUuid('batch')->middleware(['prevent.duplicate', 'throttle:10,1,mass-payment-confirm'])->name('confirm');
        Route::post('{batch}/discard', 'discard')->whereUuid('batch')->middleware(['prevent.duplicate', 'throttle:20,1,mass-payment-action'])->name('discard');
        Route::post('{batch}/stop', 'stop')->whereUuid('batch')->middleware(['prevent.duplicate', 'throttle:20,1,mass-payment-action'])->name('stop');
        Route::get('{batch}/report.csv', 'report')->whereUuid('batch')->name('report');

        // A batch paid again on a cadence (payroll): core's scheduled
        // payments with type mass, set up and managed from the batch pages.
        Route::controller(MassPaymentScheduleController::class)->prefix('schedules')->as('schedules.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->middleware(['prevent.duplicate', 'throttle:10,1,mass-payment-schedule'])->name('store');
            Route::post('{schedule}/pause', 'pause')->whereNumber('schedule')->middleware('prevent.duplicate')->name('pause');
            Route::post('{schedule}/resume', 'resume')->whereNumber('schedule')->middleware('prevent.duplicate')->name('resume');
            Route::post('{schedule}/cancel', 'cancel')->whereNumber('schedule')->middleware('prevent.duplicate')->name('cancel');
        });
    });

    // ========================== Mass Payout Routes ==============================
    // Mass payouts to external accounts through one withdraw method. Layer A
    // owns both modules (mass_payout, and withdraw_money since the money
    // leaves the platform); the per-user withdraw and mass_payout switches
    // must be on as well. Batches are read through the owner's relation and
    // only as payout batches, so anything else is a 404.
    Route::prefix('mass-payouts')->as('mass-payout.')->controller(MassPayoutController::class)->middleware(['feature.enabled:mass_payout', 'feature.enabled:withdraw_money', 'feature:withdraw', 'feature:mass_payout'])->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('create', 'create')->name('create');
        Route::get('template.csv', 'template')->name('template');
        Route::post('/', 'store')->middleware(['prevent.duplicate', 'throttle:10,60,mass-payout-draft'])->name('store');
        Route::get('{batch}', 'show')->whereUuid('batch')->name('show');
        Route::get('{batch}/status', 'status')->whereUuid('batch')->middleware('throttle:120,1,mass-payout-status')->name('status');
        Route::post('{batch}/confirm', 'confirm')->whereUuid('batch')->middleware(['prevent.duplicate', 'throttle:10,1,mass-payout-confirm'])->name('confirm');
        Route::post('{batch}/discard', 'discard')->whereUuid('batch')->middleware(['prevent.duplicate', 'throttle:20,1,mass-payout-action'])->name('discard');
        Route::post('{batch}/stop', 'stop')->whereUuid('batch')->middleware(['prevent.duplicate', 'throttle:20,1,mass-payout-action'])->name('stop');
        Route::get('{batch}/report.csv', 'report')->whereUuid('batch')->name('report');
    });

    // ========================== Deposit Money Routes =============================
    Route::prefix('deposit')->as('deposit.')->controller(DepositController::class)->middleware(['feature.enabled:deposit_money', 'prevent.duplicate'])->group(function () {
        Route::get('create', 'create')->name('create');
        Route::post('store', 'store')->name('store')->middleware('feature:deposit');
        Route::get('credentials/{method_id}', 'credentials')->name('credentials');
        Route::get('history', 'history')->name('history');
        Route::get('crypto', 'cryptoDeposit')->name('crypto');
        Route::post('crypto/generate', 'generateCryptoAddress')->name('crypto.generate')->middleware('feature:deposit');
        Route::get('crypto/{trx_id}', 'cryptoAddress')->name('crypto.show');
        Route::get('crypto/{trx_id}/status', 'cryptoStatus')->name('crypto.status');
    });

    // ========================== Transfer/Send Money Routes =============================
    Route::prefix('send-money')->as('send-money.')->controller(SendMoneyController::class)->middleware(['feature.enabled:send_money', 'prevent.duplicate'])->group(function () {
        Route::get('create', 'create')->name('create');
        Route::post('store', 'store')->name('store')->middleware('feature:send_money');
    });

    // ========================== Money Request Routes =============================
    Route::prefix('request-money')->as('request-money.')->controller(RequestMoneyController::class)->middleware(['feature.enabled:request_money', 'prevent.duplicate'])->group(function () {
        Route::get('create', 'create')->name('create');
        Route::post('store', 'store')->name('store')->middleware('feature:request_money');
    });

    // ========================== International Remittance Routes =============================
    Route::prefix('remittance')->as('remittance.')->controller(RemittanceController::class)->middleware(['feature.enabled:remittance', 'prevent.duplicate'])->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('create', 'create')->name('create');
        Route::post('quote', 'quote')->name('quote')->middleware('feature:remittance');
        Route::get('review/{quoteId}', 'review')->name('review');
        Route::post('confirm', 'confirm')->name('confirm')->middleware('feature:remittance');
        Route::get('{reference}', 'show')->name('show');
    });

    // ========================== Beneficiaries =============================
    Route::resource('beneficiaries', BeneficiaryController::class)
        ->except(['show'])
        ->middleware('feature.enabled:remittance')
        ->names('beneficiaries');

    // ========================== Exchange Money Routes =============================
    Route::prefix('exchange-money')->as('exchange-money.')->controller(ExchangeMoneyController::class)->middleware(['feature.enabled:exchange_money', 'prevent.duplicate'])->group(function () {
        Route::get('create', 'create')->name('create');
        Route::post('store', 'store')->name('store')->middleware(['feature:exchange_money']);
    });

    // ========================== Virtual Card Routes =============================
    Route::prefix('virtual-card')->as('virtual-card.')->middleware(['feature.enabled:virtual_card'])->group(function () {

        // Cardholders Management
        Route::resource('cardholders', CardholdersController::class)->names('cardholders');

        // Virtual Card Request
        Route::prefix('request')->as('request.')->controller(VirtualCardRequestController::class)->group(function () {
            Route::get('index', 'index')->name('index');
            Route::post('store', 'store')->name('store');
            Route::get('eligible-wallets', 'eligibleWallets')->name('eligible-wallets'); // JSON response for virtual card eligibility check
        });

        // My Virtual Card
        Route::controller(VirtualCardController::class)->group(function () {
            Route::get('index', 'index')->name('index');
            Route::get('card-details/{id}/{provider?}', 'cardDetails')->name('card-details');

            Route::get('topup/{card}', 'topup')->name('topup');
            Route::post('topup-store', 'topupStore')->name('topup-store')->middleware(['prevent.duplicate']);

            Route::get('withdraw/{card}', 'withdraw')->name('withdraw');
            Route::post('withdraw-store', 'withdrawStore')->name('withdraw-store');

            Route::post('{card}/freeze', 'freeze')->name('freeze');
            Route::post('{card}/unfreeze', 'unfreeze')->name('unfreeze');
            Route::post('{card}/limits', 'updateLimits')->name('limits.update');
            Route::post('{card}/controls', 'updateControls')->name('controls.update');
        });
    });

    // ====================== Vendor Marketplace Routes ========================
    Route::prefix('marketplace')->as('marketplace.')->controller(MarketplaceController::class)->middleware(['feature.enabled:vendor_marketplace', 'prevent.duplicate'])->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('orders', 'orders')->name('orders');
        Route::get('become-a-vendor', 'apply')->name('apply');
        Route::post('become-a-vendor', 'applyStore')->name('apply.store');
        Route::get('{vendor}/pay', 'pay')->name('pay');
        Route::post('{vendor}/pay', 'checkout')->name('checkout');
    });

    // Stripe Connect onboarding for vendors (hosted by Stripe; these routes
    // only mint the links and reconcile the vendor's onboarding status).
    Route::prefix('marketplace/stripe')->as('marketplace.stripe.')->controller(VendorStripeConnectController::class)->middleware(['feature.enabled:vendor_marketplace'])->group(function () {
        Route::get('onboard', 'onboard')->name('onboard');
        Route::get('return', 'callback')->name('return');
        Route::get('dashboard', 'dashboard')->name('dashboard');
    });

    // =========================== Voucher Routes =============================
    Route::prefix('voucher')->as('voucher.')->controller(VoucherController::class)->middleware(['feature.enabled:vouchers'])->group(function () {
        Route::get('my', 'myVouchers')->name('my');
        Route::get('create', 'create')->name('create');
        Route::post('store', 'store')->name('store');
        Route::post('redeem', 'redeem')->name('redeem');
    });

    // =========================== Gift Card Routes =============================
    // Redeem is handled by a Bootstrap modal on the index page, posting
    // directly to user.gift-card.redeem — no standalone redeem screen.
    Route::prefix('gift-cards')->as('gift-card.')->controller(GiftCardController::class)->middleware(['feature.enabled:gift_cards'])->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('create', 'create')->name('create');
        Route::post('store', 'store')->name('store')->middleware('prevent.duplicate');
        Route::post('redeem', 'redeem')->name('redeem');
        Route::post('{giftCard}/cancel', 'cancel')->name('cancel');
    });

    // ========================== Withdraw Money Routes =============================
    Route::prefix('withdraw')->as('withdraw.')->controller(WithdrawController::class)->middleware(['feature.enabled:withdraw_money'])->group(function () {
        Route::get('create', 'create')->name('create');
        Route::post('store', 'store')->name('store')->middleware(['prevent.duplicate', 'feature:withdraw']);
        Route::get('credentials-fields/{method_id}', 'credentialsFields')->name('credentials.fields');
        Route::get('account-info/{id}', [WithdrawAccountController::class, 'accountInfo'])->name('account.info');
        Route::resource('account', WithdrawAccountController::class)->except(['show', 'destroy']);
    });

    // ========================== P2P Trading Routes =============================
    Route::prefix('p2p')->as('p2p.')->middleware(['p2p.enabled', 'feature.enabled:p2p_marketplace', 'p2p.country', 'prevent.duplicate', 'throttle:30,1'])->group(function () {
        // Offers
        Route::prefix('offers')->as('offers.')->controller(OfferController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('my', 'my')->name('my');
            Route::get('create', 'create')->name('create');
            Route::post('store', 'store')->name('store');
            Route::get('{offer}/edit', 'edit')->name('edit');
            Route::match(['put', 'patch'], '{offer}', 'update')->name('update');
            Route::post('{offer}/toggle', 'toggle')->name('toggle');
            Route::delete('{offer}', 'destroy')->name('destroy');
            Route::get('{offer}', 'show')->name('show');
        });

        Route::prefix('offers/{offer}/promotion')->as('offers.promotion.')->controller(OfferPromotionController::class)->group(function () {
            Route::get('/', 'promote')->name('show');
            Route::post('quote', 'quote')->name('quote');
            Route::post('purchase', 'purchase')->name('purchase');
        });

        // Advertisers
        Route::prefix('advertisers')->as('advertisers.')->controller(AdvertiserController::class)->group(function () {
            Route::get('{user}', 'show')->name('show');
        });

        Route::prefix('payment-accounts')->as('payment-accounts.')->controller(PaymentAccountController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::put('{paymentAccount}', 'update')->name('update');
            Route::delete('{paymentAccount}', 'destroy')->name('destroy');
            // Not 'file': a group controller action is first tried as a class name,
            // and class names ignore case - the File facade alias would win.
            Route::get('{paymentAccount}/files/{field}', 'viewFile')->name('file');
        });

        // Orders
        Route::prefix('orders')->as('orders.')->controller(OrderController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store'); // create from offer
            Route::get('{order}', 'show')->name('show');
            Route::get('{order}/status', 'status')->name('status');
            Route::post('{order}/paid', 'markPaid')->name('paid');
            Route::post('{order}/release', 'release')->name('release');
            Route::post('{order}/cancel', 'cancel')->name('cancel');
            Route::post('{order}/feedback', 'feedback')->name('feedback');
            Route::get('{order}/payment-files/{side}/{field}', 'paymentFile')->whereIn('side', ['payer', 'receiver'])->name('payment-file');
        });

        // Order chat: own throttle bucket so the 6s polling never starves the
        // shared p2p budget, and no duplicate-submission guard (repeating the
        // same chat line is legitimate).
        Route::prefix('orders/{order}/messages')->as('orders.messages.')->controller(OrderChatController::class)
            ->withoutMiddleware(['prevent.duplicate', 'throttle:30,1'])
            ->middleware('throttle:120,1,p2p-chat')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::get('{message}/attachment', 'attachment')->name('attachment');
            });

        // Disputes
        Route::post('orders/{order}/dispute', [DisputeController::class, 'store'])->name('orders.dispute');
    });

    // ========================== Support Management Routes =============================
    Route::prefix('support-ticket')->as('support-ticket.')->controller(TicketController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('create', 'create')->name('create');
        Route::post('store', 'store')->name('store');
        Route::get('show/{ticket}', 'show')->name('show');
        Route::post('reply/{ticket}', 'reply')->name('reply');
        Route::post('close/{ticket}', 'close')->name('close');
    });

    // ========================== Transaction Routes =============================
    Route::prefix('transaction')->as('transaction.')->controller(TransactionController::class)->group(function () {
        Route::get('index', [TransactionController::class, 'index'])->name('index');
        Route::get('download-pdf/{trx_id}', [TransactionController::class, 'downloadPdf'])->name('download-pdf');
        Route::post('action', [TransactionController::class, 'handleAction'])->name('action');
    });

    // ========================== Referral Routes =============================
    Route::prefix('referral')->as('referral.')->controller(ReferralController::class)->middleware(['feature.enabled:referral_program'])->group(function () {
        Route::get('index', 'index')->name('index');
    });

    // ========================== User Rank Routes =============================
    Route::get('rank-showcase', [UserRankController::class, 'showcase'])
        ->middleware('feature.enabled:user_ranks')
        ->name('rank.showcase');

    // ========================== Payment Link Routes =============================
    Route::prefix('payment-links')->as('payment-links.')->controller(PaymentLinkController::class)->middleware(['feature.enabled:payment_link', 'prevent.duplicate'])->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('{paymentLink}/edit', 'edit')->name('edit');
        Route::match(['put', 'patch'], '{paymentLink}', 'update')->name('update');
        Route::post('{paymentLink}/toggle', 'toggle')->name('toggle');
        Route::delete('{paymentLink}', 'destroy')->name('destroy');
    });

    // ========================== Subscription Routes =============================
    Route::prefix('subscription')->as('subscription.')->controller(SubscriptionController::class)->middleware(['feature.enabled:subscription_system'])->group(function () {
        Route::get('plans', 'plans')->name('plans');
        Route::get('current', 'current')->name('current');
        Route::get('history', 'history')->name('history');
        Route::get('checkout/{plan:slug}/{cycle?}', 'checkout')->name('checkout');
        Route::post('subscribe', 'subscribe')->name('subscribe');
        Route::post('{subscription}/cancel', 'cancel')->name('cancel');
        Route::post('{subscription}/renew', 'renew')->name('renew');
    });

    // ========================== Merchant Routes =============================
    Route::middleware('can:merchant')->group(function () {
        Route::resource('merchant', MerchantController::class)->except(['show', 'destroy']);
        Route::get('merchant/{merchant}/config', [MerchantController::class, 'merchantConfig'])->name('merchant.config');
        Route::put('merchant/{merchant}/payment-methods', [MerchantController::class, 'updatePaymentMethods'])->name('merchant.payment-methods.update');
        Route::post('merchant/switch-environment', [MerchantController::class, 'switchEnvironment'])->name('merchant.switch-environment');
        // The legacy merchant QR / payment-link flow has been merged into
        // the unified Payment Link module (see user.payment-links.*).
        // Merchants now create per-shop payment links from there.
    });

    // ========================== Agent QR Cash-Out Routes =============================
    Route::middleware('feature.enabled:agent_program')->group(function () {
        Route::get('agent/qr/{token}/cash-out', [AgentOperationController::class, 'showQrCashOut'])->name('agent.qr.cash-out');
        Route::post('agent/qr/{token}/cash-out', [AgentOperationController::class, 'storeQrCashOut'])->name('agent.qr.cash-out.store');
    });

    // ========================== Agent Routes =============================
    Route::middleware(['can:agent', 'feature.enabled:agent_program'])->group(function () {
        Route::post('agent/cash-in', [AgentOperationController::class, 'cashIn'])->name('agent.cash-in');
        Route::post('agent/cash-out/otp', [AgentOperationController::class, 'sendCashOutOtp'])->name('agent.cash-out.otp');
        Route::post('agent/cash-out', [AgentOperationController::class, 'cashOut'])->name('agent.cash-out');
        Route::post('agent/operations/{operation}/mark-cash-paid', [AgentOperationController::class, 'markCashPaid'])->name('agent.cash-out.mark-paid');
        Route::post('agent/{agent}/regenerate-qr', [AgentController::class, 'regenerateQr'])->name('agent.regenerate-qr');
        Route::resource('agent', AgentController::class)->except(['show', 'destroy']);
    });

    // Notification Management Routes
    Route::controller(NotificationController::class)->prefix('notifications')->as('notifications.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('recent', 'recent')->name('recent');
        Route::get('signature', 'signature')->name('signature');
        Route::put('preferences', 'updatePreference')->name('preferences.update');

        Route::get('{id}/read', 'markAsRead')->name('markAsRead');
        Route::get('read-all', 'markAllAsRead')->name('read-all');
    });

    // Web Push subscription routes for the installed PWA
    Route::controller(PushSubscriptionController::class)->prefix('push-subscriptions')->as('push-subscriptions.')->group(function () {
        // Not 'config': a group controller action is first tried as a class name,
        // and class names ignore case - the Config facade alias would win.
        Route::get('config', 'showConfig')->name('config');
        Route::post('/', 'store')->name('store');
        Route::delete('/', 'destroy')->name('destroy');
    });
});

/*
|--------------------------------------------------------------------------
| Instant Payment Notification (IPN)
|--------------------------------------------------------------------------
*/
Route::match(['get', 'post'], '/ipn/{gateway}', [IPNController::class, 'handleIPN'])->name('ipn.handle');

// CashMaal documents a POST checkout, so the customer is sent here and the
// page forwards them. Signed because it carries the payer's email and amount.
Route::get('/payment/cashmaal/redirect', CashmaalRedirectController::class)
    ->name('gateway.cashmaal.redirect')
    ->middleware('signed');

/*
|--------------------------------------------------------------------------
| Auto-KYC provider webhooks
|--------------------------------------------------------------------------
| Each configured provider (Smile ID / ShuftiPro / Sumsub) posts its verdict
| here. The {provider} segment is the KycProvider code; the driver verifies its
| own signature. CSRF-exempt via bootstrap/app.php (`webhooks/kyc/*`).
*/
Route::post('/webhooks/kyc/{provider}', [KycWebhookController::class, 'handle'])
    ->name('webhooks.kyc');

/*
|--------------------------------------------------------------------------
| Bill payment provider webhooks
|--------------------------------------------------------------------------
| The {providerCode} segment is the BillPaymentProvider code; its driver
| authenticates the callback. A notice never moves money: it is deduplicated,
| bound to one payment of that provider, and only triggers a status check.
| CSRF-exempt via bootstrap/app.php (`webhooks/bill-payments/*`).
*/
Route::post('/webhooks/bill-payments/{providerCode}', BillPaymentWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.bill-payments');

/*
|--------------------------------------------------------------------------
| Observability acknowledge callback
|--------------------------------------------------------------------------
| Telegram callback_query, Slack interactivity and Discord interaction all
| post here to stop the escalation ladder, and all write the same activity row
| a click inside the admin panel writes.
|
| Public because the provider making the call is not signed in, so the URL
| carries a Laravel signature instead — which is also why the callback payload
| holds only the group id. CSRF-exempt via bootstrap/app.php.
*/
Route::post('/webhooks/observability/{groupId}/acknowledge', [ObservabilityWebhookController::class, 'acknowledge'])
    ->middleware('signed')
    ->name('webhooks.observability.acknowledge');

// Payment Status Routes
Route::prefix('status')->as('status.')->controller(StatusController::class)->group(function () {
    Route::match(['get', 'post'], 'success', 'success')->name('success');
    Route::match(['get', 'post'], 'cancel', 'cancel')->name('cancel');
    Route::match(['get', 'post'], 'pending', 'pending')->name('pending');
    Route::match(['get', 'post'], 'callback', 'callback')->name('callback');
});

// ========================== Merchant Payment Routes =============================
Route::prefix('payment')->as('payment.')->controller(MerchantPaymentReceiveController::class)->group(function () {
    Route::get('checkout', 'paymentCheckoutSigned')->name('checkout')->middleware('signed');
    Route::get('pay/{merchant}/{token}', 'paymentCheckoutPublic')->name('pay');
    Route::post('process', 'processPayment')->name('process');
    Route::get('wallet-pay/{token}', 'walletPayment')->name('wallet.pay');
    // Not behind `feature.enabled`: the payer is a guest identified by wallet
    // id and PIN inside the action, and the middleware evaluated the KYC rule
    // against nobody — every hosted-checkout payment, including those started
    // from the merchant API, was refused with "complete your KYC". The
    // controller applies the same rule to the resolved payer instead.
    Route::post('complete', 'completePayment')->name('complete');
    Route::match(['get', 'post'], 'with-account', 'payWithAccount')->name('with.account')->middleware(['auth', 'feature.enabled:merchant_payment']);
});

// ========================== Mobile Money Approval Routes =============================
/*
 | Where MTN MoMo and Airtel Money send the payer once the prompt is on their
 | phone. Signed per transaction by the gateway that sent the prompt - the
 | payer may be a guest - and the status poll asks the provider, never the
 | browser, before anything is settled.
 */
Route::prefix('payment/mobile-money')->as('payment.mobile-money.')->controller(MobileMoneyPaymentController::class)->middleware('signed')->group(function () {
    Route::get('{trx}', 'wait')->name('wait');
    Route::get('{trx}/status', 'status')->name('status')->middleware('throttle:30,1');
});

// ========================== Public Payment Link Routes =============================
/*
 | The whole public checkout surface is gated, not just the pay endpoint.
 | Gating only the submit left a scanned QR opening a working-looking checkout
 | that answered the payer's submit with a bare 404 — the page has to be gone
 | at the door, not at the till.
 */
Route::prefix('payment-link')->as('payment-link.')->controller(PaymentLinkCheckoutController::class)->middleware(['feature.enabled:payment_link'])->group(function () {
    Route::get('{token}', 'show')->name('show');
    // The public pay endpoint accepts Wallet PIN payments and matching active
    // gateway payments. A coarse per-IP rate limit on top of the per-(IP,wallet)
    // RateLimiter inside the controller blocks bulk wallet credential probing.
    Route::post('{token}/pay', 'pay')->name('pay')->middleware(['throttle:10,1']);
    Route::get('{token}/success', 'success')->name('success');
});

/*
|--------------------------------------------------------------------------
| Common Routes
|--------------------------------------------------------------------------
*/
Route::get('locale-set/{locale}', [LocaleController::class, 'setLocale'])->name('locale-set');
// Get currency rate with JSON response.
//
// Signed in and throttled. Nothing in the product calls this — it is retained
// for customer themes — and while it was open it was an unauthenticated way to
// drive the platform's metered rate provider one paid request at a time. It
// now serves stored rates, so the throttle is about scraping rather than cost.
Route::get('currency-rate/{fromCurrency}/{toCurrency}', [AppController::class, 'getCurrencyRate'])
    ->middleware(['auth', 'throttle:30,1'])
    ->name('get-currency-rate');
// Download File
Route::get('/file/download/{filePath}', [FileController::class, 'download'])->where('filePath', '.*')->name('file.download');

Route::prefix('summernote')->as('summernote.')->controller(SummernoteController::class)->group(function () {
    Route::post('image-upload', 'imageUpload')->name('image-upload');
    Route::post('image-delete', 'imageDelete')->name('image-delete');
});

/*
|--------------------------------------------------------------------------
| Merchant Api Documentation
|--------------------------------------------------------------------------
*/
Route::prefix('api-docs')->as('api-docs.')->group(function () {
    Route::get('/', [ApiDocsController::class, 'index'])->name('index');
});

/*
|--------------------------------------------------------------------------
| Public Software-Demo Disclosure Page
|--------------------------------------------------------------------------
|
| Always-available authoritative landing page describing the nature of
| this installation. Referenced by the demo banner, footer attribution,
| and meta-tag disclosures so automated scanners and human reviewers
| can resolve a single canonical URL.
*/

Route::get('demo-disclosure', DemoDisclosureController::class)->name('demo.disclosure');

/*
|--------------------------------------------------------------------------
| LAST: CMS Dynamic Page (Slug-Based)
|--------------------------------------------------------------------------
*/

Route::get('{slug}', PageController::class)
    ->where('slug', '^(?!admin|user|merchant|api|dashboard|payment|login|register|signin|signup|demo-disclosure).*$')
    ->name('page.view');
